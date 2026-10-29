<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Scoring\Enums\FeedbackRating;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Scoring\Models\ScoreFeedback;
use Illuminate\Support\Facades\DB;

/**
 * Records a member's verdict on a lead score — "looks right" / "looks wrong", with an optional note.
 * One current verdict per member per score: voting again replaces it. The score and grade the
 * member was looking at are snapshotted onto the row, because a later re-score updates the score in
 * place and the verdict must keep describing what they actually saw.
 *
 * The MVP captures the signal only; tuning weights and prompts from it is post-MVP.
 */
final class RecordScoreFeedbackAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(LeadScore $score, User $actor, FeedbackRating $rating, ?string $comment = null): ScoreFeedback
    {
        $teamId = (int) $score->team_id;

        return $this->teamContext->run($teamId, fn (): ScoreFeedback => DB::transaction(function () use ($score, $actor, $rating, $comment, $teamId): ScoreFeedback {
            $feedback = ScoreFeedback::query()->updateOrCreate(
                ['lead_score_id' => $score->getKey(), 'user_id' => $actor->getKey()],
                [
                    'team_id' => $teamId,
                    'rating' => $rating,
                    'comment' => $comment,
                    'scored_value' => $score->score,
                    'scored_grade' => $score->grade,
                ],
            );

            // IDs and the verdict only — never the lead's data (CLAUDE.md logging rules).
            $this->audit->log('scoring.feedback_recorded', $score, [
                'lead_id' => $score->lead_id,
                'icp_id' => $score->icp_id,
                'rating' => $rating->value,
                'grade' => $score->grade->value,
            ], $actor);

            return $feedback;
        }));
    }
}
