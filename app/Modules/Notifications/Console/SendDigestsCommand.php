<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Data\DigestSummary;
use App\Modules\Notifications\Data\PushMessage;
use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Mail\DigestMail;
use App\Modules\Notifications\Models\NotificationSetting;
use App\Modules\Notifications\Services\DigestBuilder;
use App\Modules\Notifications\Services\NotificationPreferences;
use App\Modules\Notifications\Services\PushDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends the daily and weekly round-ups (Phase 12). Scheduled **hourly**, because "8am" means 8am
 * wherever the team is.
 *
 * ### Timezone correctness, which is the risk mvp.md flags for this phase
 *
 * The member chooses an hour; the team chooses a timezone. So the sweep runs every hour and, for
 * each candidate, converts `now()` into **that team's** timezone before comparing it with the
 * chosen hour. A single nightly run at 08:00 UTC would mail Dubai at noon and get later every time
 * the clocks changed in London.
 *
 * Idempotence is `digest_sent_at` plus {@see DigestFrequency::isDue()}: an hour is seen once, but a
 * retry, an overlapping run or a DST fold could show it twice, and a gap of half a window is
 * comfortably past either.
 *
 * An empty digest is **not sent** — see {@see DigestBuilder}. The row is still stamped, so a quiet
 * day doesn't make the sweep retry all afternoon.
 */
final class SendDigestsCommand extends Command
{
    protected $signature = 'notifications:digest
        {--dry-run : Report what would be sent without sending or stamping anything}';

    protected $description = 'Send the daily and weekly notification round-ups that are due now.';

    /**
     * Longest headline a push carries. A notification shows two lines, and the rest is payload the
     * platform may reject — and it is untrusted third-party text, so the cut is ours.
     */
    private const int MAX_PUSH_BODY = 160;

    public function __construct(
        private readonly NotificationPreferences $preferences,
        private readonly PushDelivery $delivery,
    ) {
        parent::__construct();
    }

    public function handle(DigestBuilder $builder): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $candidates = NotificationSetting::query()
            ->whereIn('digest', [DigestFrequency::Daily->value, DigestFrequency::Weekly->value])
            ->with(['user', 'team'])
            ->get();

        $sent = 0;
        $empty = 0;
        $failed = 0;
        $notDue = 0;

        foreach ($candidates as $setting) {
            $user = $setting->user;
            $team = $setting->team;

            if (! $user instanceof User || ! $team instanceof Team) {
                continue;
            }

            // The team's clock, not the server's. The whole correctness of this command.
            $localNow = Carbon::now($team->timezone);

            if (! $setting->digest->isDue($localNow, $setting->digest_hour, $setting->digest_sent_at)) {
                $notDue++;

                continue;
            }

            // A member who has left keeps their settings row (it cascades on the user, not the
            // membership) and must not keep getting the team's digest.
            if ($user->roleOn($team) === null) {
                continue;
            }

            $summary = $builder->build($user, $team, $setting->digest, $localNow);

            if ($dryRun) {
                $summary->isEmpty() ? $empty++ : $sent++;

                continue;
            }

            // Stamped either way: a quiet day must not leave the sweep trying again every hour
            // until something happens.
            $setting->forceFill(['digest_sent_at' => now()])->save();

            if ($summary->isEmpty()) {
                $empty++;

                continue;
            }

            // **Per member, so one failure is one lost digest.** Without this, a queue or mailer
            // error aborts the loop: the member it happened to loses their digest (already
            // stamped), and so does everybody the sweep had not reached yet — who are not stamped
            // and so are skipped until the next hourly run, by which time their hour has passed.
            try {
                $this->mail($user, $team, $summary);
                // After the mail and in its own try, so a push failure cannot lose a digest that
                // has already been emailed — see `deliverPush()`.
                $this->push($user, $team, $summary);
                $sent++;
            } catch (Throwable $e) {
                $failed++;

                Log::channel('notifications')->error('Could not send a digest.', [
                    'team_id' => $team->getKey(),
                    'user_id' => $user->getKey(),
                    'exception' => $e::class,
                ]);
            }
        }

        Log::channel('notifications')->info('Digests swept.', [
            'sent' => $sent,
            'empty' => $empty,
            'failed' => $failed,
            'not_due' => $notDue,
            'dry_run' => $dryRun,
        ]);

        $this->info($dryRun
            ? "Would send {$sent} digests ({$empty} would have nothing to say)."
            : "Sent {$sent} digests ({$empty} had nothing to say, {$failed} failed).");

        return self::SUCCESS;
    }

    /**
     * The lock-screen half of the round-up (S15 task 1).
     *
     * **Only when the member's own preferences ask for push**, resolved through
     * {@see NotificationPreferences} rather than assumed — the same gate every other notification
     * passes, so a member who wants the email and not the buzz gets exactly that.
     *
     * It names the **hottest signal** rather than reporting a total, because a lock screen has room
     * for one thing and "eleven things happened" is a sentence somebody reads and does not act on.
     * With no signal to name it falls back to the count, which is still worth a buzz for the member
     * who asked for one.
     *
     * Its own try/catch, and that is the point of its placement: the email has already gone by the
     * time this runs, and a provider outage must not make a delivered digest count as failed —
     * which would log an error about a member who got their digest perfectly well.
     *
     * **Why this does not go through `NotificationDispatcher`**, which CLAUDE.md §2 otherwise
     * requires of everything: a digest is not a notification. It has no `notifications` row, no
     * in-app entry, no read state and no payload of keys and params — it is a **sweep** that
     * renders a Mailable with sections, and `mail()` beside this method has always called
     * `Mail::to()` directly for the same reason. The dispatcher's four jobs are done here by the
     * sweep itself: the team is explicit, a member who has left is already excluded by the
     * candidate query, the preference is read through `NotificationPreferences` above, and there is
     * no stored payload to keep prose out of. Raising a *notification* about signals is
     * `AnnounceSignals`' job and it does use the dispatcher.
     */
    private function push(User $user, Team $team, DigestSummary $summary): void
    {
        $wantsPush = in_array(
            NotificationChannel::Push,
            $this->preferences->channelsFor($user, $team, NotificationType::Digest),
            strict: true,
        );

        if (! $wantsPush) {
            return;
        }

        try {
            $this->delivery->to($user, $this->pushMessage($team, $summary));
        } catch (Throwable $e) {
            Log::channel('notifications')->warning('Could not push a digest.', [
                'team_id' => $team->getKey(),
                'user_id' => $user->getKey(),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * The push, rendered once under the **team's** locale.
     *
     * The team's rather than the member's, because that is the only per-recipient locale the
     * platform stores — the limitation the module doc already records, and the same choice
     * `TeamNotification::toPush()` makes.
     */
    private function pushMessage(Team $team, DigestSummary $summary): PushMessage
    {
        $previous = App::getLocale();
        App::setLocale($team->locale);

        try {
            $highlight = $summary->leadHighlight();
            $weekly = $summary->frequency === DigestFrequency::Weekly;

            return new PushMessage(
                title: $weekly ? __('Your week on Alima') : __('Your day on Alima'),
                body: $highlight === null
                    // `trans_choice`, not `__`: Arabic inflects the counted noun across four
                    // bands, so a fixed singular is ungrammatical for everything but one
                    // (brand review).
                    ? trans_choice(':count things happened. Here is the round-up.', $summary->total, ['count' => $summary->total])
                    // The headline itself: it is the article's own words and the whole reason this
                    // is worth a buzz at all — and **bounded**, because it is untrusted
                    // third-party text going to a lock screen (review). A provider is free to
                    // return a 400-character headline; the platforms truncate at wildly different
                    // points and some reject an oversized payload outright, so the cut is ours and
                    // made at a width a notification actually shows.
                    : Str::limit($highlight->headline, self::MAX_PUSH_BODY),
                data: [
                    'type' => NotificationType::Digest->value,
                    'team_id' => (string) $team->getKey(),
                    'url' => $highlight === null ? '/dashboard' : '/signals',
                ],
            );
        } finally {
            App::setLocale($previous);
        }
    }

    private function mail(User $user, Team $team, DigestSummary $summary): void
    {
        Mail::to($user->email)
            // The team's locale for the rendered view, which is the only per-recipient locale the
            // platform stores (see the module doc's limitations).
            ->locale($team->locale)
            ->send(new DigestMail($team, $summary, (string) $user->name));
    }
}
