<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
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
function webScoredLead(int $score = 82, ScoreGrade $grade = ScoreGrade::A): array
{
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    [$lead, $leadScore] = app(TeamContext::class)->run($team, function () use ($team, $score, $grade): array {
        $icp = Icp::factory()->active()->create(['team_id' => $team->getKey()]);
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        $leadScore = LeadScore::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'icp_id' => $icp->getKey(),
            'score' => $score,
            'grade' => $grade,
        ]);

        return [$lead, $leadScore];
    });

    return [$user, $team, $lead, $leadScore];
}

it('redirects a guest away from the score screen', function (): void {
    $this->get('/leads/1/score')->assertRedirect(route('login'));
});

it('renders the grade, breakdown and explanation', function (): void {
    [$user, , $lead, $score] = webScoredLead();

    $this->actingAs($user)
        ->get(route('scoring.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('scoring/Show')
            ->where('lead.id', $lead->getKey())
            ->where('score.grade', 'A')
            ->where('score.score', $score->score)
            ->where('score.deterministic_score', $score->deterministic_score)
            ->has('score.breakdown', 1)
            ->where('score.breakdown.0.match', 'matched')
            ->where('score.explanation', $score->explanation)
            ->where('can.rescore', true)
            ->where('can.giveFeedback', true));
});

it('tells the member there is nothing to grade against when no ICP is active', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    $this->actingAs($user)
        ->get(route('scoring.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('score', null)
            ->where('activeIcp', null));
});

it('shows a lead that is not graded yet without a score', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = app(TeamContext::class)->run($team, function () use ($team): Lead {
        Icp::factory()->active()->create(['team_id' => $team->getKey(), 'name' => 'UK coffee']);

        return Lead::factory()->create(['team_id' => $team->getKey()]);
    });

    $this->actingAs($user)
        ->get(route('scoring.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('score', null)
            ->where('activeIcp.name', 'UK coffee'));
});

it('only surfaces the score taken against the ICP that is active now', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = app(TeamContext::class)->run($team, function () use ($team): Lead {
        $retired = Icp::factory()->create(['team_id' => $team->getKey(), 'name' => 'Old profile']);
        $current = Icp::factory()->active()->create(['team_id' => $team->getKey(), 'name' => 'New profile']);
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        LeadScore::factory()->grade(ScoreGrade::D, 10)->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'icp_id' => $retired->getKey(),
        ]);
        LeadScore::factory()->grade(ScoreGrade::A, 90)->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'icp_id' => $current->getKey(),
        ]);

        return $lead;
    });

    $this->actingAs($user)
        ->get(route('scoring.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('score.grade', 'A')
            ->where('score.icp.name', 'New profile'));
});

it('queues a forced re-score for an owner', function (): void {
    Queue::fake();

    [$user, $team, $lead] = webScoredLead();

    $this->actingAs($user)
        ->from(route('scoring.show', ['lead' => $lead->getKey()]))
        ->post(route('scoring.store', ['lead' => $lead->getKey()]))
        ->assertRedirect(route('scoring.show', ['lead' => $lead->getKey()]));

    Queue::assertPushed(ScoreLeadJob::class, fn (ScoreLeadJob $job): bool => $job->leadId === (int) $lead->getKey()
        && $job->teamId === (int) $team->getKey()
        && $job->force === true);
});

it('refuses a re-score for a plain member', function (): void {
    Queue::fake();

    [, $team, $lead] = webScoredLead();

    $member = User::factory()->create();
    app(TeamMembership::class)->add($member, $team, TeamRole::Member);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($member)
        ->post(route('scoring.store', ['lead' => $lead->getKey()]))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('refuses a re-score when no ICP is active, with the error envelope', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    $this->actingAs($user)
        ->postJson(route('scoring.store', ['lead' => $lead->getKey()]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'scoring.no_active_icp');

    Queue::assertNothingPushed();
});

it('records a verdict from the score screen', function (): void {
    [$user, , $lead] = webScoredLead();

    $this->actingAs($user)
        ->from(route('scoring.show', ['lead' => $lead->getKey()]))
        ->post(route('scoring.feedback', ['lead' => $lead->getKey()]), [
            'rating' => 'bad',
            'comment' => '   Too generous.   ',
        ])
        ->assertRedirect(route('scoring.show', ['lead' => $lead->getKey()]));

    $feedback = ScoreFeedback::query()->withoutGlobalScopes()->firstOrFail();

    expect($feedback->rating)->toBe(FeedbackRating::Bad)
        // The request trims the note before it is stored.
        ->and($feedback->comment)->toBe('Too generous.');
});

it('validates the verdict', function (): void {
    [$user, , $lead] = webScoredLead();

    $this->actingAs($user)
        ->postJson(route('scoring.feedback', ['lead' => $lead->getKey()]), ['rating' => 'maybe'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rating');

    $this->actingAs($user)
        ->postJson(route('scoring.feedback', ['lead' => $lead->getKey()]), [
            'rating' => 'good',
            'comment' => str_repeat('x', 501),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('comment');
});

it('refuses a verdict from a read-only member', function (): void {
    [, $team, $lead] = webScoredLead();

    $readOnly = User::factory()->create();
    app(TeamMembership::class)->add($readOnly, $team, TeamRole::ReadOnly);
    $readOnly->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($readOnly)
        ->post(route('scoring.feedback', ['lead' => $lead->getKey()]), ['rating' => 'good'])
        ->assertForbidden();

    expect(ScoreFeedback::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a verdict on a lead that has no score yet', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = app(TeamContext::class)->run($team, function () use ($team): Lead {
        Icp::factory()->active()->create(['team_id' => $team->getKey()]);

        return Lead::factory()->create(['team_id' => $team->getKey()]);
    });

    $this->actingAs($user)
        ->postJson(route('scoring.feedback', ['lead' => $lead->getKey()]), ['rating' => 'good'])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'scoring.not_scored');
});
