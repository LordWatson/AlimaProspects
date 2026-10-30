<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Services\SignalRetention;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One signal, as the feed card and the API read it.
 *
 * Two deliberate omissions. **`raw` never leaves the server** — it is the clamped Serper payload,
 * useful for debugging and no business of a client. And there is **no contact field of any kind**,
 * because there is none on the row: a signal holds a person's name and public role and nothing
 * else (ADR 0011, data minimisation).
 *
 * Labels are sent alongside values so a screen never maps an enum itself, and are **translation
 * keys** passed through `t()` on the client — the feed is EN/AR (ADR 0007), so no finished English
 * is baked into a response.
 *
 * @mixin Signal
 */
final class SignalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Signal $signal */
        $signal = $this->resource;

        return [
            'id' => $signal->id,
            'event_type' => $signal->event_type->value,
            'event_type_label' => $signal->event_type->label(),
            'event_type_description' => $signal->event_type->description(),
            'status' => $signal->status->value,
            'status_label' => $signal->status->label(),
            'dismiss_reason' => $signal->dismiss_reason?->value,
            'dismiss_reason_label' => $signal->dismiss_reason?->label(),
            'headline' => $signal->headline,
            'summary' => $signal->summary,
            'subject_person_name' => $signal->subject_person_name,
            'subject_person_role' => $signal->subject_person_role,
            'subject_company_name' => $signal->subject_company_name,
            'counterparty_name' => $signal->counterparty_name,
            // Integer minor units + an ISO code, formatted on the client in the team's locale
            // (CLAUDE.md money rule) — never a pre-formatted string.
            'amount_minor' => $signal->amount_minor,
            'currency' => $signal->currency?->code(),
            'location' => $signal->location,
            'confidence' => $signal->confidence,
            'relevance' => $signal->relevance,
            'suggested_approach' => $signal->suggested_approach,
            'source_url' => $signal->source_url,
            'source_name' => $signal->source_name,
            'published_at' => $signal->published_at?->toIso8601String(),
            'actioned_at' => $signal->actioned_at?->toIso8601String(),
            // What the retention sweep will do to this row, and when (S6 / compliance.md §5a).
            // Sent rather than computed on the client, and read from the same `SignalRetention`
            // the sweep itself uses — so the promise on screen and the rule that deletes the row
            // are the same rule, not two copies of it.
            'deletes_in_days' => app(SignalRetention::class)->deletesInDays($signal),
            // The watch that found it, when loaded — so a team running several knows which.
            'watch' => $this->whenLoaded('watch', fn (): ?array => $signal->watch === null ? null : [
                'id' => $signal->watch->id,
                'name' => $signal->watch->name,
            ]),
            // Where a converted signal went. Null on an unconverted one, and null on a converted
            // one whose lead has since been deleted (the FK nulls), which the card reads as "the
            // lead is gone" rather than "never converted".
            'lead' => $this->whenLoaded('lead', fn (): ?array => $signal->lead === null ? null : [
                'id' => $signal->lead->id,
                'company_name' => $signal->lead->company_name,
            ]),
            'created_at' => $signal->created_at?->toIso8601String(),
        ];
    }
}
