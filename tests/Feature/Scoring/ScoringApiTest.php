<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Enums\FeedbackRating;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Jobs\ScoreLeadJob;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Scoring\Models\ScoreFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A lead with a score against its team's active ICP, owned by a fresh user's team.
 *
 * @return array{User, Team, Lead, LeadScore}
 */
function apiScoredLead(): array
{
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    [$lead, $score] = app(TeamContext::class)->run($team, function () use ($team): array {
        $icp = Icp::factory()->active()->create(['team_id' => $team->getKey(), 'name' => 'UK coffee']);
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        $score = LeadScore::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'icp_id' => $icp->getKey(),
            'score' => 82,
            'grade' => ScoreGrade::A,
        ]);

        return [$lead, $score];
    });

    return [$user, $team, $lead, $score];
}

it('requires authentication', function (): void {
    $this->getJson(route('api.v1.scoring.show', ['lead' => 1]))->assertUnauthorized();
});

it('returns the score as a resource', function (): void {
    [$user, , $lead] = apiScoredLead();

    $this->actingAs($user)
        ->getJson(route('api.v1.scoring.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertJsonPath('data.lead_id', (int) $lead->getKey())
        ->assertJsonPath('data.grade', 'A')
        ->assertJsonPath('data.score', 82)
        ->assertJsonPath('data.icp.name', 'UK coffee')
        ->assertJsonPath('data.breakdown.0.match', 'matched')
        ->assertJsonPath('data.my_feedback', null)
        ->assertJsonStructure([
            'data' => [
                'id', 'score', 'grade', 'grade_label', 'grade_description',
                'deterministic_score', 'ai_score', 'explanation', 'strengths',
                'concerns', 'excluded', 'breakdown', 'scored_at',
            ],
        ]);
});

it('404s with the error envelope when the lead has no score', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    $this->actingAs($user)
        ->getJson(route('api.v1.scoring.show', ['lead' => $lead->getKey()]))
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'scoring.not_scored');
});

it('queues a re-score and answers 202', function (): void {
    Queue::fake();

    [$user, $team, $lead] = apiScoredLead();

    $this->actingAs($user)
        ->postJson(route('api.v1.scoring.store', ['lead' => $lead->getKey()]))
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(ScoreLeadJob::class, fn (ScoreLeadJob $job): bool => $job->leadId === (int) $lead->getKey()
        && $job->teamId === (int) $team->getKey()
        && $job->force === true);
});

it('refuses a re-score for a read-only member', function (): void {
    Queue::fake();

    [, $team, $lead] = apiScoredLead();

    $readOnly = User::factory()->create();
    app(TeamMembership::class)->add($readOnly, $team, TeamRole::ReadOnly);
    $readOnly->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($readOnly)
        ->postJson(route('api.v1.scoring.store', ['lead' => $lead->getKey()]))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('records a verdict and returns the score carrying it', function (): void {
    [$user, , $lead] = apiScoredLead();

    $this->actingAs($user)
        ->postJson(route('api.v1.scoring.feedback', ['lead' => $lead->getKey()]), [
            'rating' => 'bad',
            'comment' => 'Too generous.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.my_feedback.rating', 'bad')
        ->assertJsonPath('data.my_feedback.comment', 'Too generous.');

    expect(ScoreFeedback::query()->withoutGlobalScopes()->first()?->rating)->toBe(FeedbackRating::Bad);
});

it('validates the verdict', function (): void {
    [$user, , $lead] = apiScoredLead();

    $this->actingAs($user)
        ->postJson(route('api.v1.scoring.feedback', ['lead' => $lead->getKey()]), [])
        ->assertStatus(422)
        ->assertApiValidationErrors('rating');
});
