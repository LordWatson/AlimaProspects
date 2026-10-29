<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\BusinessIntelligence\Actions\RerunBackgroundCheckAction;
use App\Modules\BusinessIntelligence\Enums\BackgroundCheckStatus;
use App\Modules\BusinessIntelligence\Exceptions\BackgroundCheckException;
use App\Modules\BusinessIntelligence\Jobs\RunBackgroundCheckJob;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A ready report for the given team with findings worth merging/seeding.
 */
function readyReport(Team $team, array $overrides = []): BusinessIntelReport
{
    return BusinessIntelReport::factory()->create(array_merge([
        'team_id' => $team->getKey(),
        'status' => BackgroundCheckStatus::Ready,
        'findings' => [
            'summary' => 'A UK marketing agency.',
            'offering' => 'Demand generation for B2B SaaS.',
            'positioning' => 'Premium and results-first.',
            'target_customers' => ['Head of Marketing', 'Founder'],
            'competitors' => ['Beta Agency'],
            'suggested_industries' => ['SaaS', 'Marketing'],
            'confidence' => 0.8,
        ],
    ], $overrides));
}

it('renders the review screen with the latest report and the team profile', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, fn () => BusinessProfile::factory()->completed()->create(['team_id' => $team->getKey()]));
    $report = readyReport($team);

    $this->actingAs($user)
        ->get(route('business-intel.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('business-intel/Review')
            ->where('report.id', $report->id)
            ->where('report.status', 'ready')
            ->where('can.manage', true));
});

it('reruns: opens a new pending version and dispatches the job', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, fn () => BusinessProfile::factory()->completed()->create(['team_id' => $team->getKey()]));
    readyReport($team, ['version' => 1]);

    $this->actingAs($user)
        ->post(route('business-intel.rerun'))
        ->assertRedirect(route('business-intel.show'));

    $latest = BusinessIntelReport::query()->withoutGlobalScopes()
        ->where('team_id', $team->getKey())->orderByDesc('version')->firstOrFail();

    expect($latest->version)->toBe(2)
        ->and($latest->status)->toBe(BackgroundCheckStatus::Pending);

    Queue::assertPushed(RunBackgroundCheckJob::class);
});

it('accepts a report: fills blank profile fields, seeds an ICP, and marks it accepted', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, fn () => BusinessProfile::factory()->completed()->create([
        'team_id' => $team->getKey(),
        'industry' => null,
        'value_proposition' => null,
    ]));
    $report = readyReport($team);

    $this->actingAs($user)
        ->post(route('business-intel.accept', ['report' => $report->id]), [
            'apply_to_profile' => true,
            'seed_icp' => true,
        ])
        ->assertRedirect(route('business-intel.show'));

    app(TeamContext::class)->run($team, function () use ($team, $report): void {
        $profile = BusinessProfile::query()->where('team_id', $team->getKey())->firstOrFail();
        expect($profile->industry)->toBe('SaaS')
            ->and($profile->value_proposition)->toBe('Demand generation for B2B SaaS.');

        expect($report->fresh()->status)->toBe(BackgroundCheckStatus::Accepted);

        $icp = Icp::query()->where('team_id', $team->getKey())->with('criteria')->firstOrFail();
        expect($icp->criteria)->not->toBeEmpty();
    });
});

it('does not overwrite a profile field the team already filled', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, fn () => BusinessProfile::factory()->completed()->create([
        'team_id' => $team->getKey(),
        'industry' => 'Logistics',
    ]));
    $report = readyReport($team);

    $this->actingAs($user)
        ->post(route('business-intel.accept', ['report' => $report->id]), ['apply_to_profile' => true])
        ->assertRedirect();

    app(TeamContext::class)->run($team, function () use ($team): void {
        $profile = BusinessProfile::query()->where('team_id', $team->getKey())->firstOrFail();
        expect($profile->industry)->toBe('Logistics');
    });
});

it('forbids a read-only member from rerunning or accepting', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, fn () => BusinessProfile::factory()->completed()->create(['team_id' => $team->getKey()]));
    $report = readyReport($team);

    $member = User::factory()->create();
    app(TeamMembership::class)->add($member, $team, TeamRole::ReadOnly);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($member->refresh())
        ->post(route('business-intel.rerun'))
        ->assertForbidden();

    $this->actingAs($member->refresh())
        ->post(route('business-intel.accept', ['report' => $report->id]))
        ->assertForbidden();

    expect($report->fresh()->status)->toBe(BackgroundCheckStatus::Ready);
});

it('scopes reports to the active team (cross-tenant accept fails)', function (): void {
    $userA = createUserWithTeam();
    $teamA = $userA->currentTeam()->firstOrFail();

    $teamB = Team::factory()->create();
    $reportB = readyReport($teamB);

    // userA (on teamA) must not be able to accept teamB's report.
    $this->actingAs($userA)
        ->post(route('business-intel.accept', ['report' => $reportB->id]))
        ->assertNotFound();

    expect($reportB->fresh()->status)->toBe(BackgroundCheckStatus::Ready);
});

it('refuses to rerun while a check is already pending', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, function () use ($team): void {
        BusinessProfile::factory()->completed()->create(['team_id' => $team->getKey()]);
        BusinessIntelReport::factory()->pending()->create(['team_id' => $team->getKey(), 'version' => 1]);
    });

    expect(fn () => app(RerunBackgroundCheckAction::class)->handle($team, $user))
        ->toThrow(BackgroundCheckException::class);

    expect(BusinessIntelReport::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(1);
    Queue::assertNotPushed(RunBackgroundCheckJob::class);
});

it('reruns past a stale pending report instead of locking the team out', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $stale = app(TeamContext::class)->run($team, function () use ($team) {
        BusinessProfile::factory()->completed()->create(['team_id' => $team->getKey()]);

        return BusinessIntelReport::factory()->pending()->create(['team_id' => $team->getKey(), 'version' => 1]);
    });
    // Strand it 30 minutes ago (past the staleness window).
    $stale->forceFill(['created_at' => now()->subMinutes(30)])->save();

    $this->actingAs($user)
        ->post(route('business-intel.rerun'))
        ->assertRedirect(route('business-intel.show'));

    $stale->refresh();
    $latest = BusinessIntelReport::query()->withoutGlobalScopes()
        ->where('team_id', $team->getKey())->orderByDesc('version')->firstOrFail();

    expect($stale->status)->toBe(BackgroundCheckStatus::Failed)
        ->and($latest->version)->toBe(2)
        ->and($latest->status)->toBe(BackgroundCheckStatus::Pending);
    Queue::assertPushed(RunBackgroundCheckJob::class);
});

it('will not accept a report that is not ready', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $pending = app(TeamContext::class)->run($team, fn () => BusinessIntelReport::factory()->pending()->create(['team_id' => $team->getKey()]));

    $this->actingAs($user)
        ->post(route('business-intel.accept', ['report' => $pending->id]), ['apply_to_profile' => true])
        ->assertRedirect();

    expect($pending->fresh()->status)->toBe(BackgroundCheckStatus::Pending);
});

it('validates the accept payload', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $report = readyReport($team);

    $this->actingAs($user)
        ->postJson(route('business-intel.accept', ['report' => $report->id]), ['apply_to_profile' => 'not-a-bool'])
        ->assertStatus(422);
});

it('requires authentication for the API', function (): void {
    $this->getJson('/api/v1/business-intel')->assertUnauthorized();
});

it('returns null over the API when the team has no report', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->getJson('/api/v1/business-intel')
        ->assertOk()
        ->assertExactJson(['data' => null]);
});

it('reads the latest report over the API', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $report = readyReport($team);

    $this->actingAs($user)
        ->getJson('/api/v1/business-intel')
        ->assertOk()
        ->assertJsonPath('data.id', $report->id)
        ->assertJsonPath('data.status', 'ready');
});
