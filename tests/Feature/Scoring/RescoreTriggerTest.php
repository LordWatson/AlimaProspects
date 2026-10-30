<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Events\LeadEnriched;
use App\Modules\ICP\Actions\UpdateIcpAction;
use App\Modules\ICP\Data\CriterionData;
use App\Modules\ICP\Data\IcpData;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Events\IcpUpdated;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Jobs\ScoreLeadJob;
use App\Modules\Scoring\Listeners\RescoreTeamOnIcpChange;
use App\Modules\Scoring\Listeners\ScoreEnrichedLead;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues a score when a lead has just been enriched', function (): void {
    Queue::fake();

    app(ScoreEnrichedLead::class)->handle(new LeadEnriched(teamId: 4, leadId: 11));

    Queue::assertPushed(ScoreLeadJob::class, function (ScoreLeadJob $job): bool {
        return $job->teamId === 4
            && $job->leadId === 11
            && $job->icpId === null
            // Opportunistic, not forced: an unchanged lead keeps its score and spends nothing.
            && $job->force === false;
    });
});

it('re-scores every lead in the team when the active ICP changes', function (): void {
    Queue::fake();

    $team = Team::factory()->create();

    $leads = app(TeamContext::class)->run($team, fn () => Lead::factory()->count(3)->create([
        'team_id' => $team->getKey(),
    ]));

    app(RescoreTeamOnIcpChange::class)->handle(new IcpUpdated((int) $team->getKey(), 42));

    Queue::assertPushed(ScoreLeadJob::class, 3);

    foreach ($leads as $lead) {
        Queue::assertPushed(ScoreLeadJob::class, function (ScoreLeadJob $job) use ($team, $lead): bool {
            return $job->leadId === (int) $lead->getKey()
                && $job->teamId === (int) $team->getKey()
                && $job->icpId === 42
                // Forced: the definition they were scored against has moved, so the scores are
                // stale by definition.
                && $job->force === true;
        });
    }
});

it('queues nothing when the team is left with no active ICP', function (): void {
    Queue::fake();

    $team = Team::factory()->create();
    app(TeamContext::class)->run($team, fn () => Lead::factory()->count(2)->create(['team_id' => $team->getKey()]));

    app(RescoreTeamOnIcpChange::class)->handle(new IcpUpdated((int) $team->getKey(), null));

    Queue::assertNothingPushed();
});

it('never crosses teams when re-scoring', function (): void {
    Queue::fake();

    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    app(TeamContext::class)->run($teamA, fn () => Lead::factory()->count(2)->create(['team_id' => $teamA->getKey()]));
    app(TeamContext::class)->run($teamB, fn () => Lead::factory()->count(5)->create(['team_id' => $teamB->getKey()]));

    app(RescoreTeamOnIcpChange::class)->handle(new IcpUpdated((int) $teamA->getKey(), 1));

    Queue::assertPushed(ScoreLeadJob::class, 2);
});

it('queues the re-score fan-out off the request when the ICP editor saves a change', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $icp = app(TeamContext::class)->run($team, function () use ($team): Icp {
        $icp = Icp::factory()->active()->create(['team_id' => $team->getKey()]);
        Lead::factory()->count(2)->create(['team_id' => $team->getKey()]);

        return $icp->load(['criteria', 'team']);
    });

    app(UpdateIcpAction::class)->handle($icp, new IcpData(
        name: 'UK coffee roasters',
        description: null,
        isActive: true,
        criteria: [new CriterionData(CriterionType::Industry, 'Coffee roasting', 80)],
    ), $user);

    // The listener is itself queued, so saving an ICP never fans out inside the request — it
    // queues one piece of work that then walks the team's leads in chunks.
    Queue::assertPushed(
        CallQueuedListener::class,
        fn (CallQueuedListener $job): bool => $job->class === RescoreTeamOnIcpChange::class,
    );
});
