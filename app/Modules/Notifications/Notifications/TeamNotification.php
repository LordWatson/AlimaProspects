<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Data\NotificationContent;
use App\Modules\Notifications\Data\PushMessage;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationPreferences;
use App\Modules\Notifications\Services\NotificationPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\App;

/**
 * **The** notification class (Phase 12). One class for every type, rather than one class per type.
 *
 * That is a deliberate choice and worth defending. The per-type classes Laravel's docs show would
 * each need their own copy of the three things that are actually hard here — resolving the member's
 * channel preferences, stamping the `team_id`, and keeping the in-app payload free of prose — and a
 * new type would be a new chance to forget one. What genuinely varies between types is the
 * *wording*, and that lives in {@see NotificationPresenter}, where it can be read as a whole.
 *
 * Three things to know:
 *
 * - **`via()` is the gate.** It asks {@see NotificationPreferences} and returns only the Laravel
 *   drivers the member has allowed, so a switched-off channel is never sent rather than sent and
 *   discarded. An empty list means nothing is sent at all.
 * - **`toDatabase()` and `toBroadcast()` store keys and params, never prose.** The row is read days
 *   later, possibly after the member has switched the app to Arabic (ADR 0007).
 * - **`toMail()` and the push body are rendered once, under the team's locale.** A member reading
 *   the app in English in an Arabic team still gets their notifications in the team's language,
 *   because the team's locale is the only per-recipient locale the platform stores. Noted as a
 *   limitation in the module doc.
 *
 * Queued, because a notification may fan out to every member of a team and the thing that raised it
 * (a finished discovery run, a logged outcome) must not wait on an SMTP round trip.
 */
final class TeamNotification extends BaseNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $payload  Ids, counts and names — never a formed sentence.
     */
    public function __construct(
        public readonly Team $team,
        public readonly NotificationType $type,
        public readonly array $payload = [],
    ) {
        // The producers all dispatch after commit already; this holds even if a future caller
        // forgets to, so a notification can never read a row its own transaction hasn't written.
        // Set through Queueable's own method rather than by redeclaring `$afterCommit`, which the
        // trait already defines and PHP refuses to see redeclared with a type.
        $this->afterCommit();
    }

    /**
     * The Laravel drivers to send over, from the member's own preferences.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $channels = App::make(NotificationPreferences::class)
            ->channelsFor($notifiable, $this->team, $this->type);

        $drivers = [];

        foreach ($channels as $channel) {
            foreach ($channel->drivers() as $driver) {
                $drivers[] = $driver;
            }
        }

        return array_values(array_unique($drivers));
    }

    /**
     * What the bell reads. Keys and params; the sentence is composed at render time.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->content()->toStored();
    }

    /**
     * The same payload over Reverb, so an open tab raises a toast and bumps the bell without a page
     * load. On the member's **own** channel, not the team's: a notification is addressed to one
     * person, and broadcasting it to `teams.{id}` would show everyone's reminders to everyone.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            // `id` lets the open tab mark it read without re-fetching the list.
            'id' => $this->id,
            ...$this->content()->toStored(),
            'created_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * The **event name** the client listens for. `broadcastAs()` is the hook for this;
     * `broadcastType()` is not — it sets the payload's `type` field, and using it here both left
     * the event broadcasting under its framework FQCN (so `Echo.listen('.notification.created')`
     * never fired) and overwrote the product type the bell reads.
     */
    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * The payload's `type`. `BroadcastNotificationCreated` merges this over whatever
     * `toBroadcast()` returned, so it has to be the product's own type value — the same one
     * `toDatabase()` stores — or the bell would receive a live notification it could not classify.
     */
    public function broadcastType(): string
    {
        return $this->type->value;
    }

    /**
     * Rendered now, in the team's language. Plain and short on purpose — one line, one button —
     * because a notification email that reads like a newsletter gets filtered, and then the member
     * misses the next one that mattered.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content();

        return $this->inTeamLocale(function () use ($content): MailMessage {
            $message = (new MailMessage)
                ->subject(__($content->title, $content->params))
                ->greeting(__('Hello'))
                ->line(__($content->body, $content->params));

            if ($content->url !== null) {
                $message->action(
                    __($content->actionLabel ?? 'Open Alima'),
                    url($content->url),
                );
            }

            return $message->line(__('You can change which notifications you get in your settings.'));
        });
    }

    /**
     * The lock-screen version. Title and body are finished prose in the team's language; `data`
     * carries the ids the app needs to open the right screen.
     */
    public function toPush(object $notifiable): PushMessage
    {
        $content = $this->content();

        return $this->inTeamLocale(fn (): PushMessage => new PushMessage(
            title: __($content->title, $content->params),
            body: __($content->body, $content->params),
            data: array_filter([
                'type' => $this->type->value,
                'team_id' => (string) $this->team->getKey(),
                'url' => $content->url ?? '',
                'notification_id' => (string) $this->id,
            ], static fn (string $value): bool => $value !== ''),
        ));
    }

    /**
     * Whether this notification wanted the in-app channel at all — read by the dispatcher's
     * digest path, which must not swallow something that was only ever going to the bell.
     */
    public function wantsInApp(User $user): bool
    {
        return in_array(
            NotificationChannel::InApp,
            App::make(NotificationPreferences::class)->channelsFor($user, $this->team, $this->type),
            strict: true,
        );
    }

    private function content(): NotificationContent
    {
        return App::make(NotificationPresenter::class)->present($this->type, $this->payload);
    }

    /**
     * Run a renderer with the team's locale active, restoring whatever was set afterwards.
     *
     * The queue worker's locale is whatever the last job left it as, so this cannot be left to the
     * ambient setting — a digest would otherwise go out in the language of whoever happened to
     * trigger the previous job.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $render
     * @return TReturn
     */
    private function inTeamLocale(callable $render): mixed
    {
        $previous = App::getLocale();
        App::setLocale($this->team->locale);

        try {
            return $render();
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * Keeps the stored `type` column human-readable and stable, rather than this class's FQCN
     * repeated on every row — and gives the bell something to filter on without decoding `data`.
     */
    public function databaseType(object $notifiable): string
    {
        return 'alima.'.$this->type->value;
    }
}
