<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Resources;

use App\Modules\LeadSourcing\Http\Resources\LeadResource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Http\Resources\LeadScoreResource;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything the lead detail screen needs, in one response: the lead, its score against the team's
 * active ICP, and its timeline. The mobile detail screen needs all three, and three round trips
 * over a phone connection is three chances to half-load a screen.
 *
 * The score is picked from the already-loaded `scores` relation, so composing this costs no query.
 * Expects a lead loaded by `FindLeadAction`.
 *
 * @mixin Lead
 */
final class LeadDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Lead $lead */
        $lead = $this->resource;

        $score = LeadScore::activeFor($lead);

        return [
            'lead' => LeadResource::make($lead)->resolve($request),
            'score' => $score === null ? null : LeadScoreResource::make($score)->resolve($request),
            'activities' => LeadActivityResource::collection($lead->activities)->resolve($request),
        ];
    }
}
