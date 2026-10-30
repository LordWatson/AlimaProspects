<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Actions\RecordScoreFeedbackAction;
use App\Modules\Scoring\Enums\FeedbackRating;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Scoring\Models\ScoreFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A scored lead belonging to the user's team.
 *
 * @return array{User, Team, Lead, LeadScore}
 */
function scoredLeadFor(?User $user = null, int $score = 82, ScoreGrade $grade = ScoreGrade::A): array
{
    $user ??= createUserWithTeam();
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

it('records a verdict with a snapshot of the score the member saw', function (): void {
    [$user, $team, , $score] = scoredLeadFor();

    $feedback = app(RecordScoreFeedbackAction::class)
        ->handle($score, $user, FeedbackRating::Bad, 'Wrong industry entirely.');

    expect($feedback->rating)->toBe(FeedbackRating::Bad)
        ->and($feedback->comment)->toBe('Wrong industry entirely.')
        ->and((int) $feedback->team_id)->toBe((int) $team->getKey())
        ->and((int) $feedback->user_id)->toBe((int) $user->getKey())
        ->and($feedback->scored_value)->toBe(82)
        ->and($feedback->scored_grade)->toBe(ScoreGrade::A);
});

it('keeps the snapshot even after the score is re-scored', function (): void {
    [$user, $team, , $score] = scoredLeadFor();

    $feedback = app(RecordScoreFeedbackAction::class)->handle($score, $user, FeedbackRating::Good);

    // A later re-score updates the score row in place; the verdict still describes grade A / 82.
    app(TeamContext::class)->run($team, fn () => $score->forceFill([
        'score' => 30,
        'grade' => ScoreGrade::D,
    ])->save());

    expect($feedback->fresh()?->scored_value)->toBe(82)
        ->and($feedback->fresh()?->scored_grade)->toBe(ScoreGrade::A);
});

it('replaces a member’s earlier verdict rather than stacking them', function (): void {
    [$user, , , $score] = scoredLeadFor();

    $action = app(RecordScoreFeedbackAction::class);
    $first = $action->handle($score, $user, FeedbackRating::Good);
    $second = $action->handle($score, $user, FeedbackRating::Bad, 'Changed my mind.');

    expect((int) $second->getKey())->toBe((int) $first->getKey())
        ->and($second->rating)->toBe(FeedbackRating::Bad)
        ->and(ScoreFeedback::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('keeps one verdict per member on the same score', function (): void {
    [$owner, $team, , $score] = scoredLeadFor();

    $colleague = User::factory()->create();
    app(TeamMembership::class)->add($colleague, $team, TeamRole::Member);

    $action = app(RecordScoreFeedbackAction::class);
    $action->handle($score, $owner, FeedbackRating::Good);
    $action->handle($score, $colleague, FeedbackRating::Bad);

    expect(ScoreFeedback::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('audits the verdict with ids only, never the lead’s data', function (): void {
    [$user, , $lead, $score] = scoredLeadFor();

    app(RecordScoreFeedbackAction::class)->handle($score, $user, FeedbackRating::Bad);

    $entry = AuditLog::query()->where('action', 'scoring.feedback_recorded')->firstOrFail();

    expect($entry->subject_type)->toBe(LeadScore::class)
        ->and((int) $entry->subject_id)->toBe((int) $score->getKey())
        ->and((int) $entry->user_id)->toBe((int) $user->getKey())
        ->and($entry->properties)->toMatchArray([
            'lead_id' => (int) $lead->getKey(),
            'rating' => 'bad',
            'grade' => 'A',
        ])
        ->and(json_encode($entry->properties))->not->toContain($lead->company_name);
});

it('accepts a verdict with no comment at all', function (): void {
    [$user, , , $score] = scoredLeadFor();

    $feedback = app(RecordScoreFeedbackAction::class)->handle($score, $user, FeedbackRating::Good);

    expect($feedback->comment)->toBeNull()
        ->and($feedback->rating)->toBe(FeedbackRating::Good);
});
