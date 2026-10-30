<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Http\Resources;

use App\Modules\Scoring\Data\CriterionScore;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Scoring\Models\ScoreFeedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lead's ICP-fit score as the web payload and `/api/v1` both serialise it: the blended score and
 * grade, both halves, the per-criterion breakdown with its evidence, the explanation, and the
 * viewer's own feedback when the relation was loaded for them.
 *
 * @mixin LeadScore
 */
final class LeadScoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'score' => $this->score,
            'grade' => $this->grade->value,
            'grade_label' => $this->grade->label(),
            'grade_description' => $this->grade->description(),
            'deterministic_score' => $this->deterministic_score,
            'ai_score' => $this->ai_score,
            'explanation' => $this->explanation,
            'strengths' => $this->strengths ?? [],
            'concerns' => $this->concerns ?? [],
            'excluded' => $this->excluded,
            'excluded_by' => $this->excluded_by,
            'breakdown' => array_map(
                static fn (CriterionScore $row): array => [
                    'type' => $row->type->value,
                    'type_label' => $row->type->label(),
                    'value' => $row->value,
                    'weight' => $row->weight,
                    'match' => $row->match->value,
                    'match_label' => $row->match->label(),
                    'contribution' => $row->contribution,
                    'evidence' => $row->evidence,
                ],
                $this->breakdown,
            ),
            'model' => $this->model,
            'prompt_version' => $this->prompt_version,
            'cost_micros' => $this->cost_micros,
            'scored_at' => $this->scored_at?->toIso8601String(),
            'icp' => $this->whenLoaded('icp', fn (): ?array => $this->icp === null ? null : [
                'id' => $this->icp->id,
                'name' => $this->icp->name,
                'is_active' => $this->icp->is_active,
            ]),
            // The viewer's own verdict, when the relation was loaded scoped to them.
            'my_feedback' => $this->whenLoaded('feedback', fn (): ?array => $this->feedback
                ->map(static fn (ScoreFeedback $feedback): array => [
                    'rating' => $feedback->rating->value,
                    'comment' => $feedback->comment,
                    'created_at' => $feedback->created_at?->toIso8601String(),
                ])
                ->first()),
        ];
    }
}
