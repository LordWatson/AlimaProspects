<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ComplianceFixtures;

uses(RefreshDatabase::class);

/**
 * What discovery needs over the API: an active ICP, and the recorded legitimate-interest assessment
 * the Phase 15 gate requires (see `StartDiscoveryRunTest::seedActiveIcp`).
 */
function seedApiIcp(Team $team): void
{
    ComplianceFixtures::activeLia($team);

    app(TeamContext::class)->run($team, function () use ($team): void {
        $icp = Icp::query()->create(['team_id' => $team->getKey(), 'name' => 'UK Marketing', 'is_active' => true]);
        $icp->criteria()->create(['team_id' => $team->getKey(), 'type' => CriterionType::Industry, 'value' => 'Marketing', 'weight' => 80, 'position' => 0]);
    });
}

it('requires authentication', function (): void {
    $this->getJson(route('api.v1.lead-sourcing.runs.index'))->assertUnauthorized();
});

it('lists the team’s discovery runs', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    app(TeamContext::class)->run($team, fn () => DiscoveryRun::factory()->completed()->count(2)->create(['team_id' => $team->getKey()]));

    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.lead-sourcing.runs.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.source', 'companies_house');
});

it('starts a run over the API', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    seedApiIcp($user->currentTeam()->firstOrFail());

    $this->actingAs($user, 'sanctum')
        ->postJson(route('api.v1.lead-sourcing.runs.store'), ['sources' => ['companies_house']])
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.source', 'companies_house')
        ->assertJsonPath('data.0.status', 'pending');

    expect(DiscoveryRun::query()->count())->toBe(1);
    Queue::assertPushed(DiscoverLeadsJob::class);
});

it('fans out to one run per source over the API', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    seedApiIcp($user->currentTeam()->firstOrFail());

    $this->actingAs($user, 'sanctum')
        ->postJson(route('api.v1.lead-sourcing.runs.store'), ['sources' => ['companies_house', 'google_places']])
        ->assertCreated()
        ->assertJsonCount(2, 'data');

    expect(DiscoveryRun::query()->count())->toBe(2);
    Queue::assertPushed(DiscoverLeadsJob::class, 2);
});

it('shows a single run with its leads', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $run = app(TeamContext::class)->run($team, function () use ($team): DiscoveryRun {
        $run = DiscoveryRun::factory()->completed()->create(['team_id' => $team->getKey()]);

        Lead::factory()->create([
            'team_id' => $team->getKey(),
            'discovery_run_id' => $run->getKey(),
            'company_name' => 'Bright Bean Coffee',
        ]);

        return $run;
    });

    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.lead-sourcing.runs.show', ['run' => $run->getKey()]))
        ->assertOk()
        ->assertJsonPath('data.id', $run->getKey())
        ->assertJsonPath('data.status', 'completed')
        // A plain list, not a nested `{ data: [...] }` wrapper — the same shape the web screen
        // gets, so the two can't drift.
        ->assertJsonCount(1, 'data.leads')
        ->assertJsonPath('data.leads.0.company_name', 'Bright Bean Coffee');
});

it('returns a validation envelope for a bad source', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user, 'sanctum')
        ->postJson(route('api.v1.lead-sourcing.runs.store'), ['sources' => ['apollo']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('sources.0');
});

it('never leaks another team’s run across the API', function (): void {
    $user = createUserWithTeam();

    $otherTeam = Team::factory()->create();
    $otherRun = app(TeamContext::class)->run($otherTeam, fn (): DiscoveryRun => DiscoveryRun::factory()->create(['team_id' => $otherTeam->getKey()]));

    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.lead-sourcing.runs.show', ['run' => $otherRun->getKey()]))
        ->assertNotFound();
});

it('forbids a read-only member from starting a run over the API', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    seedApiIcp($team);

    $member = User::factory()->create();
    app(TeamMembership::class)->add($member, $team, TeamRole::ReadOnly);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($member->refresh(), 'sanctum')
        ->postJson(route('api.v1.lead-sourcing.runs.store'), ['sources' => ['companies_house']])
        ->assertForbidden();
});
