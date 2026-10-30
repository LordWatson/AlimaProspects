<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Http\Resources;

use App\Modules\AiInsights\Models\Insight;
use App\Modules\AiInsights\Services\InsightRetention;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One insight, as the feed card and the API read it.
 *
 * Two things to know about the shape:
 *
 * - **`headline` and `body` are null for a deterministic insight**, and that is not missing data.
 *   Those two types are worded on the client from `type` + `payload` so they read in English or
 *   Arabic (ADR 0007); only the AI digest carries stored prose, written once in the team's locale.
 *   A client must therefore branch on `is_written_by_ai` rather than assuming a headline.
 * - Labels travel beside values so no screen maps an enum itself, and they are **translation
 *   keys**, not finished English.
 *
 * `payload` goes out as it is stored: ids, counts and company names, and never a contact detail —
 * there is none on the row.
 *
 * @mixin Insight
 */
final class InsightResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Insight $insight */
        $insight = $this->resource;

        return [
            'id' => $insight->id,
            'type' => $insight->type->value,
            'type_label' => $insight->type->label(),
            'type_description' => $insight->type->description(),
            'is_written_by_ai' => $insight->type->isWrittenByAi(),
            'status' => $insight->status->value,
            'status_label' => $insight->status->label(),
            'severity' => $insight->severity->value,
            'severity_label' => $insight->severity->label(),
            'headline' => $insight->headline,
            'body' => $insight->body,
            'payload' => $insight->payload,
            'period_start' => $insight->period_start?->toIso8601String(),
            'period_end' => $insight->period_end?->toIso8601String(),
            'generated_at' => $insight->generated_at->toIso8601String(),
            'dismissed_at' => $insight->dismissed_at?->toIso8601String(),
            // What the retention sweep will do to this row, and when — read from the same
            // `InsightRetention` the sweep itself uses, so the promise on screen and the rule that
            // deletes the row are one rule rather than two copies of it.
            'deletes_in_days' => app(InsightRetention::class)->deletesInDays($insight),
            // Deliberately absent: `prompt_key`, `prompt_version`, `model` and `cost_micros`. They
            // are our cost and provenance trace, they live on `ai_requests` for Reporting to add
            // up, and a team has no use for the model's name on a card.
        ];
    }
}
