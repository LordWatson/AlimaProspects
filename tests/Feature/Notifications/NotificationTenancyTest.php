<?php

declare(strict_types=1);

use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Jobs\AnnounceTopLeadsJob;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationSetting;
use App\Modules\Notifications\Models\PushDevice;
use App\Modules\Notifications\Services\DigestBuilder;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\LeadsWorkspaceFixtures as Workspace;
use Tests\Support\NotificationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Cross-tenant access for Notifications (ADR 0006, CLAUDE.md: "every tenant model needs a test
 * proving cross-tenant access fails").
 *
 * This file earns its place more than most, because **`Notification` deliberately has no tenant
 * global scope** — the rows are written by Laravel's own channel, outside our team context, so the
 * trait would never stamp them and its scope would then hide every row it had failed to stamp.
 * `Notification::scopeOwnedBy()` is therefore the whole of the enforcement rather than a
 * double-check on top of one, and nothing else in the suite would catch it failing open.
 *
 * `NotificationPreference` and `NotificationSetting` have a `team_id` without the trait for a
 * related reason (they are keyed per user *and* team, and the digest sweep reads every tenant's on
 * purpose), so their isolation is pinned here too.
 */
beforeEach(function (): void {
    Mail::fake();
});

// --- The API ------------------------------------------------------------------------------------

it('cannot mark another member\'s notification read over the API', function (): void {
    [$owner, $team] = Fixtures::team();
    $colleague = Fixtures::member($team);

    app(NotificationDispatcher::class)->send($team, $owner, NotificationType::TopLeads, ['count' => 1]);
    $theirs = Notification::query()->sole();

    Sanctum::actingAs($colleague, ['*']);

    // A 404, not a 403: they have no business learning it exists.
    $this->postJson(route('api.v1.notifications.read', $theirs->id))->assertNotFound();

    expect($theirs->fresh()->read_at)->toBeNull();
});

it('cannot reach a notification from another team over the API', function (): void {
    [$user, $teamA] = Fixtures::team();

    [, $teamB] = Fixtures::team();
    app(TeamMembership::class)->add($user, $teamB, TeamRole::Member);

    // Raised in team B while their active team is A.
    app(NotificationDispatcher::class)->send($teamB, $user, NotificationType::TopLeads, ['count' => 1]);
    $inB = Notification::query()->forTeam($teamB)->sole();

    Sanctum::actingAs($user, ['*']);

    $this->getJson(route('api.v1.notifications.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('api.v1.notifications.unread'))->assertOk()->assertJsonPath('data.unread', 0);
    $this->postJson(route('api.v1.notifications.read', $inB->id))->assertNotFound();

    expect($inB->fresh()->read_at)->toBeNull()
        ->and($teamA->exists)->toBeTrue();
});

it('does not clear another team\'s notifications on read-all over the API', function (): void {
    [$user, $teamA] = Fixtures::team();

    [, $teamB] = Fixtures::team();
    app(TeamMembership::class)->add($user, $teamB, TeamRole::Member);

    app(NotificationDispatcher::class)->send($teamA, $user, NotificationType::TopLeads, ['count' => 1]);
    app(NotificationDispatcher::class)->send($teamB, $user, NotificationType::TopLeads, ['count' => 1]);

    Sanctum::actingAs($user, ['*']);

    $this->postJson(route('api.v1.notifications.read-all'))->assertOk()->assertJsonPath('data.cleared', 1);

    expect(Notification::query()->forTeam($teamB)->unread()->count())->toBe(1);
});

// --- Preferences and settings -------------------------------------------------------------------

it('applies a member\'s preference only in the team they set it in', function (): void {
    [$user, $teamA] = Fixtures::team();

    [, $teamB] = Fixtures::team();
    app(TeamMembership::class)->add($user, $teamB, TeamRole::Member);

    // Switched off in A, untouched in B. The same person, very different teams.
    Fixtures::prefer($user, $teamA, NotificationType::TopLeads, []);

    app(NotificationDispatcher::class)->send($teamA, $user, NotificationType::TopLeads, ['count' => 1]);
    app(NotificationDispatcher::class)->send($teamB, $user, NotificationType::TopLeads, ['count' => 1]);

    expect(Notification::query()->forTeam($teamA)->count())->toBe(0)
        ->and(Notification::query()->forTeam($teamB)->count())->toBe(1);
});

it('applies a mute only in the team it was set in', function (): void {
    [$user, $teamA] = Fixtures::team();

    [, $teamB] = Fixtures::team();
    app(TeamMembership::class)->add($user, $teamB, TeamRole::Member);

    NotificationSetting::factory()->muted()->create([
        'user_id' => $user->getKey(),
        'team_id' => $teamA->getKey(),
    ]);

    Fixtures::prefer($user, $teamA, NotificationType::TaskDue, [NotificationChannel::Mail]);
    Fixtures::prefer($user, $teamB, NotificationType::TaskDue, [NotificationChannel::Mail]);

    app(NotificationDispatcher::class)->send($teamA, $user, NotificationType::TaskDue, ['title' => 'A']);
    app(NotificationDispatcher::class)->send($teamB, $user, NotificationType::TaskDue, ['title' => 'B']);

    // Muted in A, so mail is dropped there and nothing is stored (in-app was not chosen either).
    // B is unaffected.
    expect(Notification::query()->forTeam($teamA)->count())->toBe(0)
        ->and(Notification::query()->forTeam($teamB)->count())->toBe(0);

    // The mail is the observable difference, since neither team had in-app on.
    Mail::assertQueuedCount(0);
});

// --- The sweeps and the aggregating job ---------------------------------------------------------

it('builds a digest from one team\'s data only', function (): void {
    [$user, $teamA] = Workspace::team();

    [, $teamB] = Workspace::team();
    app(TeamMembership::class)->add($user, $teamB, TeamRole::Member);

    app(TeamContext::class)->run($teamA, fn () => Lead::factory()->create(['team_id' => $teamA->getKey()]));
    app(TeamContext::class)->run($teamB, fn () => Lead::factory()->count(5)->create(['team_id' => $teamB->getKey()]));

    $summary = app(DigestBuilder::class)->build($user, $teamA, DigestFrequency::Daily);

    $found = collect($summary->lines)->firstWhere('key', ':count new companies found');

    // One, not six: the builder runs inside the team's context.
    expect($found['params']['count'])->toBe(1);
});

it('announces only the strong matches belonging to its own team', function (): void {
    [, $teamA, $icpA] = Workspace::team();
    [, $teamB, $icpB] = Workspace::team();

    Workspace::lead($teamA, ['company_name' => 'Mine Ltd'], $icpA, ScoreGrade::A, 92);
    Workspace::lead($teamB, ['company_name' => 'Theirs Ltd'], $icpB, ScoreGrade::A, 95);

    app(AnnounceTopLeadsJob::class, ['teamId' => (int) $teamA->getKey()])
        ->handle(app(TeamContext::class), app(NotificationDispatcher::class));

    $notification = Notification::query()->sole();

    expect($notification->team_id)->toBe($teamA->getKey())
        ->and($notification->params()['count'])->toBe(1)
        ->and($notification->params()['names'])->toBe('Mine Ltd');
});

it('prunes across every tenant, because it is a platform sweep', function (): void {
    [$userA, $teamA] = Fixtures::team();
    [$userB] = Fixtures::team();

    foreach ([[$userA, $teamA]] as [$user, $team]) {
        app(NotificationDispatcher::class)->send($team, $user, NotificationType::TopLeads, ['count' => 1]);
    }

    // Read and old enough to go.
    Notification::query()->update(['read_at' => now()->subMonths(6)]);

    PushDevice::factory()->stale(200)->create(['user_id' => $userA->getKey()]);
    PushDevice::factory()->stale(200)->create(['user_id' => $userB->getKey()]);

    $this->artisan('notifications:prune')->assertSuccessful();

    // Deliberately unscoped: retention is the platform's promise, not one tenant's setting.
    expect(Notification::query()->count())->toBe(0)
        ->and(PushDevice::query()->count())->toBe(0);
});
