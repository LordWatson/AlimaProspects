<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Actions\StartDiscoveryRunAction;
use App\Modules\LeadSourcing\Data\StartDiscoveryData;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ComplianceFixtures;

uses(RefreshDatabase::class);

/**
 * Give the team what discovery needs: an active ICP with an industry signal to shape a query from,
 * **and a recorded legitimate-interest assessment**.
 *
 * The assessment is not incidental. Since Phase 15 the LIA gate is real (`LegitimateInterestGate`
 * replaces the permit-everything stub), so a team with no basis on record cannot source at all —
 * which is the behaviour `DiscoveryGuardsTest` asserts directly. Every test here that expects a run
 * to start therefore needs one, and a sourcing test that passed without it would mean the gate was
 * not wired in.
 */
function seedActiveIcp(Team $team, string $industry = 'Marketing'): Icp
{
    ComplianceFixtures::activeLia($team);

    return app(TeamContext::class)->run($team, function () use ($team, $industry): Icp {
        $icp = Icp::query()->create(['team_id' => $team->getKey(), 'name' => 'UK '.$industry, 'is_active' => true]);
        $icp->criteria()->create([
            'team_id' => $team->getKey(),
            'type' => CriterionType::Industry,
            'value' => $industry,
            'weight' => 80,
            'position' => 0,
        ]);

        return $icp;
    });
}

it('starts a discovery run shaped by the active ICP and dispatches the job', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    seedActiveIcp($team);

    $this->actingAs($user)
        ->post(route('lead-sourcing.store'), ['sources' => ['companies_house']])
        ->assertRedirect();

    $run = DiscoveryRun::query()->firstOrFail();

    expect($run->status)->toBe(DiscoveryRunStatus::Pending)
        ->and($run->source)->toBe(LeadSource::CompaniesHouse)
        ->and($run->requested_limit)->toBe(25) // config default
        ->and($run->params['terms'])->toBe('Marketing')
        ->and($run->params['industries'])->toBe(['Marketing'])
        ->and($run->created_by)->toBe($user->getKey());

    Queue::assertPushed(DiscoverLeadsJob::class, fn (DiscoverLeadsJob $job): bool => $job->runId === (int) $run->getKey() && $job->teamId === (int) $team->getKey());
});

it('fans out to one run per selected source', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    seedActiveIcp($team);

    $this->actingAs($user)
        ->post(route('lead-sourcing.store'), ['sources' => ['companies_house', 'google_places']])
        ->assertRedirect(route('lead-sourcing.index'));

    $runs = DiscoveryRun::query()->orderBy('id')->get();

    expect($runs)->toHaveCount(2)
        ->and($runs->pluck('source')->map->value->all())->toBe(['companies_house', 'google_places'])
        // Both share the same resolved query (terms/limit) — only the source differs.
        ->and($runs->pluck('params.terms')->unique()->all())->toBe(['Marketing']);

    // One job dispatched per run, each targeting its own run id.
    Queue::assertPushed(DiscoverLeadsJob::class, 2);
    foreach ($runs as $run) {
        Queue::assertPushed(DiscoverLeadsJob::class, fn (DiscoverLeadsJob $job): bool => $job->runId === (int) $run->getKey() && $job->teamId === (int) $team->getKey());
    }
});

it('lets explicit terms override the ICP', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    seedActiveIcp($user->currentTeam()->firstOrFail());

    $this->actingAs($user)
        ->post(route('lead-sourcing.store'), ['sources' => ['companies_house'], 'terms' => 'coffee roasters', 'location' => 'Leeds', 'limit' => 10])
        ->assertRedirect();

    $run = DiscoveryRun::query()->firstOrFail();

    expect($run->params['terms'])->toBe('coffee roasters')
        ->and($run->params['location'])->toBe('Leeds')
        ->and($run->requested_limit)->toBe(10);
});

it('redirects to the discovery index with live progress', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    seedActiveIcp($user->currentTeam()->firstOrFail());

    $this->actingAs($user)
        ->post(route('lead-sourcing.store'), ['sources' => ['companies_house']])
        ->assertRedirect(route('lead-sourcing.index'));
});

it('validates the sources are live providers', function (): void {
    $user = createUserWithTeam();
    seedActiveIcp($user->currentTeam()->firstOrFail());

    $this->actingAs($user)
        ->post(route('lead-sourcing.store'), ['sources' => ['apollo']]) // not a known provider
        ->assertSessionHasErrors('sources.0');

    $this->actingAs($user)
        ->post(route('lead-sourcing.store'), []) // nothing selected
        ->assertSessionHasErrors('sources');

    $this->actingAs($user)
        ->post(route('lead-sourcing.store'), ['sources' => ['companies_house', 'companies_house']]) // duplicates
        ->assertSessionHasErrors('sources.0');

    expect(DiscoveryRun::query()->count())->toBe(0);
});

it('accepts Google Places and web search as live sources (Phase 7b)', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    seedActiveIcp($user->currentTeam()->firstOrFail());

    $this->actingAs($user)
        ->post(route('lead-sourcing.store'), ['sources' => ['google_places', 'web_search'], 'terms' => 'coffee roasters'])
        ->assertRedirect();

    expect(DiscoveryRun::query()->pluck('source')->map->value->all())
        ->toContain('google_places', 'web_search');
});

it('forbids a read-only member from starting a run', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    seedActiveIcp($team);

    $member = User::factory()->create();
    app(TeamMembership::class)->add($member, $team, TeamRole::ReadOnly);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($member->refresh())
        ->post(route('lead-sourcing.store'), ['sources' => ['companies_house']])
        ->assertForbidden();

    expect(DiscoveryRun::query()->count())->toBe(0);
});

it('cannot shape a run from another team’s ICP', function (): void {
    $user = createUserWithTeam();

    $otherTeam = Team::factory()->create();
    $otherIcp = app(TeamContext::class)->run($otherTeam, fn (): Icp => Icp::query()->create(['team_id' => $otherTeam->getKey(), 'name' => 'Theirs']));

    $this->actingAs($user)
        ->post(route('lead-sourcing.store'), ['sources' => ['companies_house'], 'icp_id' => $otherIcp->id])
        ->assertSessionHasErrors('icp_id');

    expect(DiscoveryRun::query()->count())->toBe(0);
});

it('refuses to start with nothing to search for', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // A lawful basis on record, so the refusal below is the one this test is about: without it the
    // LIA gate would refuse first and the assertion would pass for the wrong reason.
    ComplianceFixtures::activeLia($team);

    // No terms, and no active ICP to imply any.
    expect(fn () => app(StartDiscoveryRunAction::class)->handle(
        $team,
        new StartDiscoveryData(sources: [LeadSource::CompaniesHouse]),
        $user,
    ))->toThrow(LeadSourcingException::class);

    expect(DiscoveryRun::query()->count())->toBe(0);
});
