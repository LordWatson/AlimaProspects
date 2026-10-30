<?php

declare(strict_types=1);

use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationSetting;
use App\Modules\Notifications\Notifications\TeamNotification;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\Support\NotificationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Delivery: who gets told, on which channels, and with what stored (Phase 12).
 *
 * The cases that matter: a notification is stored with its team and with **structured values rather
 * than prose**; a switched-off channel is never sent rather than sent and discarded; a mute silences
 * the interrupting channels but not the bell; an admin-only type never reaches a plain member; and a
 * member who has left the team stops receiving its notifications.
 */
beforeEach(function (): void {
    Mail::fake();
});

// --- What is stored ------------------------------------------------------------------------------

it('stores a notification against the team, with keys and params rather than a sentence', function (): void {
    [$user, $team] = Fixtures::team();

    app(NotificationDispatcher::class)->send($team, $user, NotificationType::TopLeads, [
        'count' => 3,
        'names' => ['Acme Ltd', 'Bright Bean'],
    ]);

    $notification = Notification::query()->sole();

    expect($notification->team_id)->toBe($team->getKey())
        ->and($notification->notifiable_id)->toBe($user->getKey())
        // A stable, readable type rather than this class's FQCN on every row.
        ->and($notification->type)->toBe('alima.top_leads')
        ->and($notification->notificationType())->toBe(NotificationType::TopLeads)
        // The sentence is composed at render time — the app is EN/AR (ADR 0007).
        ->and($notification->data['title'])->toBe(':count strong new matches')
        ->and($notification->params()['count'])->toBe(3)
        ->and($notification->url())->toBe('/leads')
        ->and($notification->read_at)->toBeNull();
});

it('keeps one member\'s teams apart', function (): void {
    [$user, $teamA] = Fixtures::team();

    // The same person in a second team. Their bell in one must not show the other's.
    [, $teamB] = Fixtures::team();
    app(TeamMembership::class)->add($user, $teamB, TeamRole::Member);

    app(NotificationDispatcher::class)->send($teamA, $user, NotificationType::TopLeads, ['count' => 1]);
    app(NotificationDispatcher::class)->send($teamB, $user, NotificationType::TopLeads, ['count' => 9]);

    expect(Notification::query()->forTeam($teamA)->count())->toBe(1)
        ->and(Notification::query()->forTeam($teamA)->sole()->params()['count'])->toBe(1)
        ->and(Notification::query()->forTeam($teamB)->sole()->params()['count'])->toBe(9);
});

// --- Channels ------------------------------------------------------------------------------------

it('sends on the channels the member chose and no others', function (): void {
    [$user, $team] = Fixtures::team();

    // In-app only: no email, even though this type's defaults include one.
    Fixtures::prefer($user, $team, NotificationType::TaskDue, [NotificationChannel::InApp]);

    NotificationFacade::fake();

    app(NotificationDispatcher::class)->send($team, $user, NotificationType::TaskDue, ['title' => 'Call them']);

    NotificationFacade::assertSentTo(
        $user,
        TeamNotification::class,
        function (TeamNotification $notification, array $channels): bool {
            // In-app is two Laravel channels, deliberately — `database` for the bell on the next
            // page load, `broadcast` so it arrives without one.
            expect($channels)->toContain('database')
                ->and($channels)->toContain('broadcast')
                ->and($channels)->not->toContain('mail');

            return true;
        },
    );
});

it('sends nothing at all when the member has switched a type off', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::prefer($user, $team, NotificationType::DiscoveryComplete, []);

    app(NotificationDispatcher::class)->send($team, $user, NotificationType::DiscoveryComplete, [
        'leads_created' => 4,
    ]);

    // Not stored, not emailed. A switched-off channel is never sent rather than sent and dropped.
    expect(Notification::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('falls back to the type\'s defaults when the member has no opinion yet', function (): void {
    [$user, $team] = Fixtures::team();

    // No preference row at all — which must mean "the defaults", not "everything off", or a type
    // added later would arrive switched off for everyone who signed up before it.
    app(NotificationDispatcher::class)->send($team, $user, NotificationType::TaskDue, ['title' => 'Call them']);

    expect(Notification::query()->count())->toBe(1);
});

it('silences email and push while muted, and leaves the bell alone', function (): void {
    [$user, $team] = Fixtures::team();

    NotificationSetting::factory()->muted()->create([
        'user_id' => $user->getKey(),
        'team_id' => $team->getKey(),
    ]);

    NotificationFacade::fake();

    app(NotificationDispatcher::class)->send($team, $user, NotificationType::TaskDue, ['title' => 'Call them']);

    NotificationFacade::assertSentTo($user, TeamNotification::class, function ($notification, array $channels): bool {
        // "Stop buzzing me" is not "hide what happened": a member back from a quiet week still has
        // to be able to find out what they missed.
        expect($channels)->toContain('database')
            ->and($channels)->not->toContain('mail');

        return true;
    });
});

// --- Who gets told -------------------------------------------------------------------------------

it('tells the whole team about the team\'s work', function (): void {
    [$owner, $team] = Fixtures::team();
    $member = Fixtures::member($team);
    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);

    $count = app(NotificationDispatcher::class)->toTeam($team, NotificationType::SignalsDetected, ['count' => 2]);

    expect($count)->toBe(3)
        ->and(Notification::query()->count())->toBe(3)
        ->and(Notification::query()->pluck('notifiable_id')->sort()->values()->all())
        ->toBe(collect([$owner, $member, $readOnly])->map->getKey()->sort()->values()->all());
});

it('keeps an admin-only type away from a plain member, however their preferences read', function (): void {
    [$owner, $team] = Fixtures::team();
    $member = Fixtures::member($team);

    // They have explicitly asked for it — and still must not get it. Billing is not everyone's
    // business, and authorisation is not a preference.
    Fixtures::prefer($member, $team, NotificationType::CreditLow, [NotificationChannel::InApp]);

    app(NotificationDispatcher::class)->toAdmins($team, NotificationType::CreditLow, ['remaining_percent' => 5]);

    expect(Notification::query()->count())->toBe(1)
        ->and(Notification::query()->sole()->notifiable_id)->toBe($owner->getKey());
});

it('stops notifying someone who has left the team', function (): void {
    [, $team] = Fixtures::team();
    $member = Fixtures::member($team);

    $team->users()->detach($member->getKey());

    $count = app(NotificationDispatcher::class)->send($team, $member, NotificationType::TopLeads, ['count' => 1]);

    // Their notifications stop with their membership — and a stale assignee is not an error worth
    // failing a queued job over.
    expect($count)->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('does not notify the same person twice for one event', function (): void {
    [$user, $team] = Fixtures::team();

    app(NotificationDispatcher::class)->send($team, [$user, $user], NotificationType::TopLeads, ['count' => 1]);

    expect(Notification::query()->count())->toBe(1);
});
