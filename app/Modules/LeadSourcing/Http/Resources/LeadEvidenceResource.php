<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Http\Resources;

use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\LeadSourcing\Models\LeadEvidenceFeedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One item of evidence, as the why-now panel and the mobile detail screen read it.
 *
 * **`payload` is deliberately absent.** It is the provider's raw output, kept for audit and for
 * re-mapping without re-fetching, and on a signal-derived row it can name a person — so it does not
 * leave the database through an API resource (data minimisation, `docs/compliance.md` §3.9).
 *
 * `weight` is what the item is worth *today* — its stored reliability thinned by its age, and zero
 * once disputed. It is sent because the panel's job is to explain the number the list was ordered
 * by, and a panel that showed only the original reliability would be explaining a different figure.
 *
 * @mixin LeadEvidence
 */
final class LeadEvidenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'source' => $this->source,
            // Falls back to the source key so a row always has something to attribute itself to.
            'source_label' => $this->source_label ?? $this->source,
            'title' => $this->title,
            'summary' => $this->summary,
            'source_url' => $this->source_url,
            // When it happened, and when we found out — both, because the gap between them is the
            // honest answer to "is this fresh?".
            'observed_at' => $this->observed_at?->toIso8601String(),
            'captured_at' => $this->captured_at->toIso8601String(),
            'reliability' => $this->reliability,
            'weight' => (int) round($this->currentWeight()),
            'is_disputed' => $this->is_disputed,
            'is_timing' => $this->kind->isTiming(),
            'signal_id' => $this->signal_id,
            // The viewer's own verdict, when the caller loaded it — the panel shows a member what
            // *they* said, not what the team said, exactly as the score screen does.
            'my_verdict' => $this->whenLoaded('feedback', fn (): ?string => $this->feedback
                ->map(static fn (LeadEvidenceFeedback $row): string => $row->verdict->value)
                ->first()),
        ];
    }
}
