<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('scopes leads to the active team', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    $context = app(TeamContext::class);

    $context->run($teamA, fn () => Lead::factory()->create(['team_id' => $teamA->getKey()]));

    $context->run($teamB, function (): void {
        expect(Lead::query()->count())->toBe(0);
    });

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('allows the same dedupe key in two different teams (unique is per team)', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    $context = app(TeamContext::class);

    $context->run($teamA, fn () => Lead::factory()->create(['team_id' => $teamA->getKey(), 'dedupe_key' => 'companies_house:no:12345678']));
    $context->run($teamB, fn () => Lead::factory()->create(['team_id' => $teamB->getKey(), 'dedupe_key' => 'companies_house:no:12345678']));

    expect(Lead::query()->withoutGlobalScopes()->where('dedupe_key', 'companies_house:no:12345678')->count())->toBe(2);
});

it('redirects guests away from the discovery web routes', function (): void {
    $this->get(route('lead-sourcing.index'))->assertRedirect(route('login'));
});

it('renders the index with only the team’s own runs', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    app(TeamContext::class)->run($team, fn () => DiscoveryRun::factory()->completed()->create(['team_id' => $team->getKey()]));

    $otherTeam = Team::factory()->create();
    app(TeamContext::class)->run($otherTeam, fn () => DiscoveryRun::factory()->create(['team_id' => $otherTeam->getKey()]));

    $this->actingAs($user)
        ->get(route('lead-sourcing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('lead-sourcing/Index')->has('runs', 1));
});

it('shows the team’s own run but 404s another team’s', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->completed()->create(['team_id' => $team->getKey()]));

    $this->actingAs($user)
        ->get(route('lead-sourcing.show', ['run' => $run->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('lead-sourcing/Show')->where('run.id', $run->getKey()));

    $otherTeam = Team::factory()->create();
    $otherRun = app(TeamContext::class)->run($otherTeam, fn (): DiscoveryRun => DiscoveryRun::factory()->create(['team_id' => $otherTeam->getKey()]));

    $this->actingAs($user)
        ->get(route('lead-sourcing.show', ['run' => $otherRun->getKey()]))
        ->assertNotFound();
});

it('excludes other teams’ runs from the API index', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    app(TeamContext::class)->run($team, fn () => DiscoveryRun::factory()->create(['team_id' => $team->getKey()]));

    $otherTeam = Team::factory()->create();
    app(TeamContext::class)->run($otherTeam, fn () => DiscoveryRun::factory()->create(['team_id' => $otherTeam->getKey()]));

    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.lead-sourcing.runs.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
