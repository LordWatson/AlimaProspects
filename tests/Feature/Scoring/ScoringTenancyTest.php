<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Scoring\Models\ScoreFeedback;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A team of its own with an active ICP, a lead and a score on it.
 *
 * @return array{Team, Lead, LeadScore}
 */
function foreignScoredLead(): array
{
    $team = Team::factory()->create();

    [$lead, $score] = app(TeamContext::class)->run($team, function () use ($team): array {
        $icp = Icp::factory()->active()->create(['team_id' => $team->getKey()]);
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        $score = LeadScore::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'icp_id' => $icp->getKey(),
            'grade' => ScoreGrade::A,
        ]);

        return [$lead, $score];
    });

    return [$team, $lead, $score];
}

it('hides another team’s scores behind the global scope', function (): void {
    [$teamA] = foreignScoredLead();
    [, , $scoreB] = foreignScoredLead();

    $visible = app(TeamContext::class)->run($teamA, fn () => LeadScore::query()->get());

    expect($visible)->toHaveCount(1)
        ->and($visible->pluck('id')->all())->not->toContain((int) $scoreB->getKey())
        ->and(LeadScore::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('hides another team’s score feedback behind the global scope', function (): void {
    [$teamA, , $scoreA] = foreignScoredLead();
    [$teamB, , $scoreB] = foreignScoredLead();

    app(TeamContext::class)->run($teamA, fn () => ScoreFeedback::factory()->create([
        'team_id' => $teamA->getKey(),
        'lead_score_id' => $scoreA->getKey(),
        'user_id' => User::factory()->create()->getKey(),
    ]));
    app(TeamContext::class)->run($teamB, fn () => ScoreFeedback::factory()->create([
        'team_id' => $teamB->getKey(),
        'lead_score_id' => $scoreB->getKey(),
        'user_id' => User::factory()->create()->getKey(),
    ]));

    $visible = app(TeamContext::class)->run($teamA, fn () => ScoreFeedback::query()->get());

    expect($visible)->toHaveCount(1)
        ->and((int) $visible->first()?->team_id)->toBe((int) $teamA->getKey())
        ->and(ScoreFeedback::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('404s when a member asks for another team’s lead score', function (): void {
    $user = createUserWithTeam();
    [, $foreignLead] = foreignScoredLead();

    $this->actingAs($user)
        ->get(route('scoring.show', ['lead' => $foreignLead->getKey()]))
        ->assertNotFound();

    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.scoring.show', ['lead' => $foreignLead->getKey()]))
        ->assertNotFound();
});

it('404s rather than queueing work on another team’s lead', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    [, $foreignLead] = foreignScoredLead();

    $this->actingAs($user)
        ->post(route('scoring.store', ['lead' => $foreignLead->getKey()]))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

it('404s rather than recording a verdict on another team’s score', function (): void {
    $user = createUserWithTeam();
    [, $foreignLead] = foreignScoredLead();

    $this->actingAs($user)
        ->post(route('scoring.feedback', ['lead' => $foreignLead->getKey()]), ['rating' => 'good'])
        ->assertNotFound();

    expect(ScoreFeedback::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('keeps at most one score per lead and ICP', function (): void {
    [$team, $lead, $score] = foreignScoredLead();

    app(TeamContext::class)->run($team, function () use ($team, $lead, $score): void {
        expect(fn () => LeadScore::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'icp_id' => $score->icp_id,
        ]))->toThrow(QueryException::class);
    });
});

it('keeps at most one verdict per member and score', function (): void {
    [$team, , $score] = foreignScoredLead();
    $user = User::factory()->create();

    app(TeamContext::class)->run($team, function () use ($team, $score, $user): void {
        ScoreFeedback::factory()->create([
            'team_id' => $team->getKey(),
            'lead_score_id' => $score->getKey(),
            'user_id' => $user->getKey(),
        ]);

        expect(fn () => ScoreFeedback::factory()->create([
            'team_id' => $team->getKey(),
            'lead_score_id' => $score->getKey(),
            'user_id' => $user->getKey(),
        ]))->toThrow(QueryException::class);
    });
});

it('takes a lead’s scores with it when the lead is deleted', function (): void {
    [$team, $lead] = foreignScoredLead();

    app(TeamContext::class)->run($team, fn () => $lead->delete());

    expect(LeadScore::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('takes a score’s feedback with it when the ICP is deleted', function (): void {
    [$team, , $score] = foreignScoredLead();

    app(TeamContext::class)->run($team, function () use ($team, $score): void {
        ScoreFeedback::factory()->create([
            'team_id' => $team->getKey(),
            'lead_score_id' => $score->getKey(),
            'user_id' => User::factory()->create()->getKey(),
        ]);

        // A score only means something against the definition it measured, so the ICP takes both.
        Icp::query()->whereKey($score->icp_id)->delete();
    });

    expect(LeadScore::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(ScoreFeedback::query()->withoutGlobalScopes()->count())->toBe(0);
});
