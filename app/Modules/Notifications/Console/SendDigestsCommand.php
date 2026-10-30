<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Data\DigestSummary;
use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Mail\DigestMail;
use App\Modules\Notifications\Models\NotificationSetting;
use App\Modules\Notifications\Services\DigestBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

    private function mail(User $user, Team $team, DigestSummary $summary): void
    {
        Mail::to($user->email)
            // The team's locale for the rendered view, which is the only per-recipient locale the
            // platform stores (see the module doc's limitations).
            ->locale($team->locale)
            ->send(new DigestMail($team, $summary, (string) $user->name));
    }
}
