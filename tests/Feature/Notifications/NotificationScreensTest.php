<?php

declare(strict_types=1);

use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\NotificationSetting;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\NotificationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The bell, the history and the settings screen (Phase 12).
 *
 * The case worth most here is the **shared bell prop**: it has to be on every page, because the
 * bell is part of the app shell and a controller that forgot to load it would show a member zero
 * unread while they had five.
 */
beforeEach(function (): void {
    Mail::fake();
});

/**
 * Raise `$count` notifications for a member.
 *
 * Every one carries the same payload on purpose: rows written in the same second have no stable
 * order, so a test that asserted on the newest would be asserting on a coin toss.
 */
function notify(object $user, object $team, int $count = 1, NotificationType $type = NotificationType::TopLeads): void
{
    foreach (range(1, $count) as $ignored) {
        app(NotificationDispatcher::class)->send($team, $user, $type, ['count' => 3]);
    }
}

// --- The bell ------------------------------------------------------------------------------------

it('shares the bell on every page, with an unread count and the recent items', function (): void {
    [$user, $team] = Fixtures::team();

    notify($user, $team, 3);

    // Any page at all — the bell is shell, not a screen's responsibility.
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('notifications.unread', 3)
            ->has('notifications.items', 3)
            // Keys and params, not prose: the row may be read after the member switches to Arabic.
            ->where('notifications.items.0.title', ':count strong new matches'));
});

it('keeps another team\'s notifications out of the bell', function (): void {
    [$user, $teamA] = Fixtures::team();

    [, $teamB] = Fixtures::team();
    app(TeamMembership::class)->add($user, $teamB, TeamRole::Member);

    notify($user, $teamA, 2);
    notify($user, $teamB, 5);

    // Their active team is A.
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('notifications.unread', 2));
});

it('leaves a framework notification out of the bell', function (): void {
    [$user, $team] = Fixtures::team();

    // A password reset, say: no team, and nothing the bell could render.
    Notification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'Illuminate\\Auth\\Notifications\\ResetPassword',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => [],
        'team_id' => null,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('notifications.unread', 0));

    expect($team->exists)->toBeTrue();
});

// --- The history and marking read ----------------------------------------------------------------

it('shows the full history', function (): void {
    [$user, $team] = Fixtures::team();

    notify($user, $team, 4);

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('notifications/Index')
            ->has('notifications.data', 4)
            ->where('unreadCount', 4));
});

it('marks one read and marks them all read', function (): void {
    [$user, $team] = Fixtures::team();

    notify($user, $team, 3);

    $first = Notification::query()->first();

    $this->actingAs($user)
        ->post(route('notifications.read', $first->id))
        ->assertRedirect();

    expect($first->fresh()->read_at)->not->toBeNull()
        ->and(Notification::query()->unread()->count())->toBe(2);

    $this->actingAs($user)
        ->post(route('notifications.read-all'))
        ->assertRedirect();

    expect(Notification::query()->unread()->count())->toBe(0);
});

it('cannot mark somebody else\'s notification read', function (): void {
    [$owner, $team] = Fixtures::team();
    $stranger = Fixtures::member($team);

    notify($owner, $team, 1);
    $theirs = Notification::query()->sole();

    // A 404, not a 403: they have no business learning it exists.
    $this->actingAs($stranger)
        ->post(route('notifications.read', $theirs->id))
        ->assertNotFound();

    expect($theirs->fresh()->read_at)->toBeNull();
});

it('does not clear notifications from another team when marking all read', function (): void {
    [$user, $teamA] = Fixtures::team();

    [, $teamB] = Fixtures::team();
    app(TeamMembership::class)->add($user, $teamB, TeamRole::Member);

    notify($user, $teamA, 2);
    notify($user, $teamB, 2);

    $this->actingAs($user)->post(route('notifications.read-all'))->assertRedirect();

    // The operation that would do the most damage if it reached across teams.
    expect(Notification::query()->forTeam($teamA)->unread()->count())->toBe(0)
        ->and(Notification::query()->forTeam($teamB)->unread()->count())->toBe(2);
});

// --- Preferences ---------------------------------------------------------------------------------

it('shows the effective channels for every type, defaults included', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)
        ->get(route('notifications.preferences.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Notifications')
            ->has('options.types', count(NotificationType::configurable()))
            // A screen that showed an unset type as "off" would be lying — notifications would be
            // arriving while it said nothing was.
            ->where('preferences.task_due', ['in_app', 'mail', 'push'])
            ->where('preferences.discovery_complete', ['in_app', 'push'])
            ->where('digest.frequency', 'off')
            ->where('timezone', $team->timezone));
});

it('tells a plain member which types are not theirs to receive', function (): void {
    [, $team] = Fixtures::team();
    $member = Fixtures::member($team);

    $this->actingAs($member)
        ->get(route('notifications.preferences.edit'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $types = collect($page->toArray()['props']['options']['types'])->keyBy('value');

            // Said rather than hidden: a switch that does nothing is worse than an explanation.
            expect($types['credit_low']['available'])->toBeFalse()
                ->and($types['task_due']['available'])->toBeTrue();

            return true;
        });
});

it('saves a row for every type, not only the ones that changed', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)
        ->patch(route('notifications.preferences.update'), [
            'channels' => [
                'task_due' => ['in_app'],
                'top_leads' => [],
            ],
            'digest' => 'weekly',
            'digest_hour' => 9,
        ])
        ->assertRedirect();

    // Once a member has visited the screen they have an opinion about all of it — a later change
    // to a type's defaults must not silently override what they chose to leave alone.
    expect(NotificationPreference::query()->count())->toBe(count(NotificationType::configurable()));

    $taskDue = NotificationPreference::query()->where('type', 'task_due')->sole();

    expect($taskDue->channels())->toBe([NotificationChannel::InApp]);

    $settings = NotificationSetting::query()->sole();

    expect($settings->digest)->toBe(DigestFrequency::Weekly)
        ->and($settings->digest_hour)->toBe(9);
});

it('ignores a stale type or channel instead of losing the whole form', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)
        ->patch(route('notifications.preferences.update'), [
            'channels' => [
                // A cached page from before a rename.
                'a_type_that_no_longer_exists' => ['in_app'],
                'task_due' => ['in_app'],
            ],
            'digest' => 'off',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($team->exists)->toBeTrue()
        ->and(NotificationPreference::query()->where('type', 'task_due')->sole()->channels())
        ->toBe([NotificationChannel::InApp]);
});

it('refuses a channel it does not recognise', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->from(route('notifications.preferences.edit'))
        ->patch(route('notifications.preferences.update'), [
            'channels' => ['task_due' => ['carrier_pigeon']],
            'digest' => 'off',
        ])
        ->assertSessionHasErrors('channels.task_due.0');
});

// --- The API -------------------------------------------------------------------------------------

it('reads notifications and the unread count over the API', function (): void {
    [$user, $team] = Fixtures::team();

    notify($user, $team, 3);

    Sanctum::actingAs($user, ['*']);

    $this->getJson(route('api.v1.notifications.index'))->assertOk()->assertJsonCount(3, 'data');
    $this->getJson(route('api.v1.notifications.unread'))->assertOk()->assertJsonPath('data.unread', 3);

    $this->postJson(route('api.v1.notifications.read-all'))
        ->assertOk()
        ->assertJsonPath('data.cleared', 3);

    $this->getJson(route('api.v1.notifications.unread'))->assertOk()->assertJsonPath('data.unread', 0);
});

it('requires a token', function (): void {
    $this->getJson(route('api.v1.notifications.index'))->assertUnauthorized();
    $this->getJson(route('api.v1.push-devices.index'))->assertUnauthorized();
});
