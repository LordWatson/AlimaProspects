<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\NotificationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * What actually goes over Reverb (Phase 12).
 *
 * This file exists because the first version of `TeamNotification` got it wrong in a way nothing
 * else caught: it defined `broadcastType()` thinking that named the event, when the hook for the
 * event name is `broadcastAs()`. The notification broadcast under its framework FQCN — so the
 * bell's `Echo.listen('.notification.created')` never fired — **and** `broadcastType()`'s return
 * was merged over the payload's `type`, so the one live notification that did arrive could not be
 * classified. Both are invisible to every other test in the module, because the database channel
 * is unaffected.
 */
beforeEach(function (): void {
    Mail::fake();
});

it('broadcasts under the event name the bell listens for', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::prefer($user, $team, NotificationType::TopLeads, [NotificationChannel::InApp]);

    Event::fake([BroadcastNotificationCreated::class]);

    app(NotificationDispatcher::class)->send($team, $user, NotificationType::TopLeads, [
        'count' => 3,
        'names' => ['Acme Ltd'],
    ]);

    Event::assertDispatched(
        BroadcastNotificationCreated::class,
        function (BroadcastNotificationCreated $event) use ($user): bool {
            // The name the client subscribes to. `.notification.created` in Echo is this string.
            expect($event->broadcastAs())->toBe('notification.created');

            $payload = $event->broadcastWith();

            // The product's own type, not the class name and not the event name: the bell switches
            // on this.
            expect($payload['type'])->toBe('top_leads')
                // Keys and params, so a live notification renders the same way a stored one does.
                ->and($payload['title'])->toBe(':count strong new matches')
                ->and($payload['params']['count'])->toBe(3)
                // The id, so an open tab can mark it read without re-fetching the list.
                ->and($payload['id'])->not->toBeEmpty();

            return $event->notifiable->is($user);
        },
    );
});

it('broadcasts on the member\'s own channel, not the team\'s', function (): void {
    [$owner, $team] = Fixtures::team();
    $colleague = Fixtures::member($team);

    Fixtures::prefer($owner, $team, NotificationType::TaskDue, [NotificationChannel::InApp]);

    Event::fake([BroadcastNotificationCreated::class]);

    app(NotificationDispatcher::class)->send($team, $owner, NotificationType::TaskDue, [
        'title' => 'Call them back',
    ]);

    Event::assertDispatched(
        BroadcastNotificationCreated::class,
        function (BroadcastNotificationCreated $event) use ($owner, $colleague, $team): bool {
            $channels = array_map(
                static fn (object $channel): string => (string) $channel,
                $event->broadcastOn(),
            );

            // A notification is addressed to one person: `teams.{id}` would show one member's
            // reminders to everybody.
            expect($channels)->toBe(['private-App.Models.User.'.$owner->getKey()])
                ->and($channels[0])->not->toContain('teams.'.$team->getKey())
                ->and($channels[0])->not->toContain((string) $colleague->getKey());

            return true;
        },
    );
});

it('does not broadcast when the member wants in-app off', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::prefer($user, $team, NotificationType::TopLeads, [NotificationChannel::Mail]);

    Event::fake([BroadcastNotificationCreated::class]);

    app(NotificationDispatcher::class)->send($team, $user, NotificationType::TopLeads, ['count' => 1]);

    Event::assertNotDispatched(BroadcastNotificationCreated::class);

    expect($team)->toBeInstanceOf(Team::class);
});
