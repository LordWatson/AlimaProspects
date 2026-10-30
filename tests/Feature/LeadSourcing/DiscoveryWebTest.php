<?php

declare(strict_types=1);

use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The discovery run screens. The run **detail** screen had no coverage of the leads it lists, which
 * is how it shipped serialising them as `{ data: [...] }` instead of an array — the Vue reads
 * `run.leads.length`, so every completed run showed "No companies matched".
 */
it('lists the companies a completed run found', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $run = app(TeamContext::class)->run($team, function () use ($team): DiscoveryRun {
        $icp = Icp::factory()->active()->create(['team_id' => $team->getKey()]);

        $run = DiscoveryRun::factory()->create([
            'team_id' => $team->getKey(),
            'status' => DiscoveryRunStatus::Completed,
            'leads_found' => 2,
            'leads_created' => 2,
        ]);

        foreach (['Bright Bean Coffee', 'Dubai Dates'] as $index => $name) {
            $lead = Lead::factory()->create([
                'team_id' => $team->getKey(),
                'discovery_run_id' => $run->getKey(),
                'company_name' => $name,
                'dedupe_key' => 'no:run-'.$index,
            ]);

            LeadScore::factory()->grade(ScoreGrade::A, 88)->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'icp_id' => $icp->getKey(),
            ]);
        }

        return $run;
    });

    $this->actingAs($user)
        ->get(route('lead-sourcing.show', ['run' => $run->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('lead-sourcing/Show')
            ->where('run.id', $run->getKey())
            // A plain list, not a `{ data: [...] }` wrapper: the screen reads `run.leads.length`.
            ->has('run.leads', 2)
            ->where('run.leads.0.company_name', 'Dubai Dates')
            ->where('run.leads.1.company_name', 'Bright Bean Coffee')
            // …and each row carries the grade the Fit column renders.
            ->where('run.leads.0.score.grade', 'A'));
});

it('lists a run that found nothing as an empty list, not a missing one', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'status' => DiscoveryRunStatus::Completed,
        'leads_found' => 0,
    ]));

    $this->actingAs($user)
        ->get(route('lead-sourcing.show', ['run' => $run->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('run.leads', 0));
});

it('lists the team’s recent runs on the index', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, fn () => DiscoveryRun::factory()->count(2)->create([
        'team_id' => $team->getKey(),
    ]));

    $this->actingAs($user)
        ->get(route('lead-sourcing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('lead-sourcing/Index')
            ->has('runs', 2));
});

it('offers only the providers in the discovery picker', function (): void {
    $user = createUserWithTeam();

    // `LeadSource` also labels how a lead arrived when nobody went looking — an imported CSV, a
    // converted signal. Neither is something to search, so neither belongs on this screen even as
    // a greyed-out card (ADR 0011, S4).
    $this->actingAs($user)
        ->get(route('lead-sourcing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sources', 3)
            ->where('sources.0.value', 'companies_house')
            ->where('sources.1.value', 'google_places')
            ->where('sources.2.value', 'web_search'));
});
