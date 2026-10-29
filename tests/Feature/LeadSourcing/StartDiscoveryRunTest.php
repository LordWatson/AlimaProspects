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

uses(RefreshDatabase::class);

/**
 * Give the team an active ICP with an industry signal, so discovery can shape a query from it.
 */
function seedActiveIcp(Team $team, string $industry = 'Marketing'): Icp
{
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
        ->post(route('lead-sourcing.store'), ['source' => 'companies_house'])
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

it('lets explicit terms override the ICP', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    seedActiveIcp($user->currentTeam()->firstOrFail());

    $this->actingAs($user)
        ->post(route('lead-sourcing.store'), ['source' => 'companies_house', 'terms' => 'coffee roasters', 'location' => 'Leeds', 'limit' => 10])
        ->assertRedirect();

    $run = DiscoveryRun::query()->firstOrFail();

    expect($run->params['terms'])->toBe('coffee roasters')
        ->and($run->params['location'])->toBe('Leeds')
        ->and($run->requested_limit)->toBe(10);
});

it('redirects to the run progress screen', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    seedActiveIcp($user->currentTeam()->firstOrFail());

    $response = $this->actingAs($user)->post(route('lead-sourcing.store'), ['source' => 'companies_house']);

    $run = DiscoveryRun::query()->firstOrFail();
    $response->assertRedirect(route('lead-sourcing.show', ['run' => $run->getKey()]));
});

it('validates the source is a live provider', function (): void {
    $user = createUserWithTeam();
    seedActiveIcp($user->currentTeam()->firstOrFail());

    $this->actingAs($user)
        ->post(route('lead-sourcing.store'), ['source' => 'google_places']) // not available yet
        ->assertSessionHasErrors('source');

    $this->actingAs($user)
        ->post(route('lead-sourcing.store'), [])
        ->assertSessionHasErrors('source');

    expect(DiscoveryRun::query()->count())->toBe(0);
});

it('forbids a read-only member from starting a run', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    seedActiveIcp($team);

    $member = User::factory()->create();
    app(TeamMembership::class)->add($member, $team, TeamRole::ReadOnly);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($member->refresh())
        ->post(route('lead-sourcing.store'), ['source' => 'companies_house'])
        ->assertForbidden();

    expect(DiscoveryRun::query()->count())->toBe(0);
});

it('cannot shape a run from another team’s ICP', function (): void {
    $user = createUserWithTeam();

    $otherTeam = Team::factory()->create();
    $otherIcp = app(TeamContext::class)->run($otherTeam, fn (): Icp => Icp::query()->create(['team_id' => $otherTeam->getKey(), 'name' => 'Theirs']));

    $this->actingAs($user)
        ->post(route('lead-sourcing.store'), ['source' => 'companies_house', 'icp_id' => $otherIcp->id])
        ->assertSessionHasErrors('icp_id');

    expect(DiscoveryRun::query()->count())->toBe(0);
});

it('refuses to start with nothing to search for', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // No terms, and no active ICP to imply any.
    expect(fn () => app(StartDiscoveryRunAction::class)->handle(
        $team,
        new StartDiscoveryData(source: LeadSource::CompaniesHouse),
        $user,
    ))->toThrow(LeadSourcingException::class);

    expect(DiscoveryRun::query()->count())->toBe(0);
});
