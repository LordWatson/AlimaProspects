<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Compliance\Models\LiaRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A legitimate-interest assessment, as the compliance screen reads it.
 *
 * `status_label` and `guidance` are **translation keys**, not sentences: the app is EN/AR
 * (ADR 0007) and the wording is chosen on the client. `in_force` and `has_expired` are sent as
 * computed booleans rather than left to the client to derive from dates, so the screen and the
 * discovery gate can never disagree about whether sourcing is open.
 *
 * @mixin LiaRecord
 */
final class LiaRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Null for a transient record — the team has never saved one for this market.
            'id' => $this->exists ? $this->id : null,
            'market' => $this->market->value,
            'market_label' => $this->market->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'guidance' => $this->status->guidance(),
            'purpose' => $this->purpose ?? '',
            'necessity' => $this->necessity ?? '',
            'balancing' => $this->balancing ?? '',
            'safeguards' => $this->safeguards,
            'signals_scope' => $this->signals_scope,
            'version' => $this->version,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'review_due_at' => $this->review_due_at?->toIso8601String(),
            'withdrawn_at' => $this->withdrawn_at?->toIso8601String(),
            // Computed server-side from the same methods the gate uses.
            'in_force' => $this->isInForce(),
            'has_expired' => $this->hasExpired(),
            'review_due_in_days' => $this->reviewDueInDays(),
        ];
    }
}
