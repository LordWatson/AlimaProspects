<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
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
 * The **event label is the exception**, and since S7 it has to be: a signal's type is a row the team
 * owns (ADR 0018), so its name may be the team's own words in their own language and must not be
 * looked up in a translation file. `event_label_translatable` says which it is — true for a type
 * cloned from a preset and still carrying Alima's wording, false once a team has renamed it. Where
 * the definition has been deleted the label falls back to the stored `event_key`, humanised, which
 * is why a card never goes blank.
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
            'event_key' => $signal->event_key,
            'event_label' => $signal->eventLabel(),
            'event_description' => $signal->eventDescription(),
            'event_label_translatable' => $this->labelIsOurs($signal),
            // Null when the definition has been deleted, which the library screen reads as "this
            // type is gone" rather than offering a link to nothing.
            'signal_definition_id' => $signal->signal_definition_id,
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
            // Where the subject is, resolved deterministically at extraction (S8, ADR 0019) - and
            // the regime that follows from it, which is what decides the lead's outreach
            // restriction on conversion. Sent so a screen can explain a restriction rather than
            // presenting it as a fact with no provenance.
            'jurisdiction' => $signal->jurisdiction,
            'resolved_market' => $signal->resolved_market,
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
            // The lead this event is **about** (S10), as opposed to the one it was converted into.
            // Not `whenLoaded`: the card decides what to offer from it — "add this to Acme Ltd"
            // rather than "convert" — so a feed that forgot to eager-load it would silently show
            // every matched signal as unmatched. The relation is loaded by the list Action.
            //
            // The confidence travels with it because the member is owed it: the way account
            // monitoring goes wrong is an event pinned to the wrong company, so the weaker match
            // says so and "convert anyway" stays a visible choice.
            'matched_lead' => $signal->matched_lead_id === null ? null : [
                'id' => (int) $signal->matched_lead_id,
                'company_name' => $signal->matchedLead?->company_name,
                'confidence' => $signal->match_confidence?->value,
                'confidence_label' => $signal->match_confidence?->label(),
                'confidence_explanation' => $signal->match_confidence?->explanation(),
                'needs_confirmation' => $signal->match_confidence?->warrantsConfirmation() ?? false,
            ],
            'created_at' => $signal->created_at?->toIso8601String(),
        ];
    }

    /**
     * Whether the event label is Alima's English - and so safe to pass through `t()` - or the
     * team's own wording, which must be rendered as typed.
     *
     * One rule, shared with `SignalDefinitionResource` via the model
     * ({@see SignalDefinition::usesPlatformWording()}). Where the definition is gone the label is a
     * humanised version of our own slug, which reads as ours.
     */
    private function labelIsOurs(Signal $signal): bool
    {
        $definition = $signal->relationLoaded('definition') ? $signal->definition : null;

        return ! $definition instanceof SignalDefinition || $definition->usesPlatformWording();
    }
}
