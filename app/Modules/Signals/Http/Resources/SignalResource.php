<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Actions\ListSignalsAction;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
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
            // The other outlets that reported the same event (S11), or null when this card stands
            // alone — which is both an unclustered signal and a cluster of one. Shape:
            // `{id: int, sources_count: int (always 2+), first_published_at, last_published_at,
            // sources: [{id, source_name, source_url, published_at}]}`. Spelled out because this
            // comment is what the generated specification carries as the field's description, and
            // the free Scramble cannot infer a mapped collection (see docs/api/README.md §8).
            'cluster' => $this->clusterPayload($signal),
            'created_at' => $signal->created_at?->toIso8601String(),
        ];
    }

    /**
     * "Reported by 6 sources", and the list behind it.
     *
     * Null in three cases, all of them meaning "there is nothing extra to say": the signal has no
     * cluster, the relation was not loaded, or the cluster has one member. The last one matters —
     * every clustered signal starts as a cluster of one, and a card announcing "reported by 1
     * source" would be noise on most of the feed.
     *
     * **Not `whenLoaded`, and null rather than an exception when it is missing.** The card decides
     * what to show from this, so a caller that forgot to eager-load should get a card with no
     * sources panel rather than a 500 — and outside production `preventLazyLoading` makes the
     * forgetting loud anyway. {@see ListSignalsAction} loads it.
     *
     * The members carry a link, an outlet and a date and **nothing else**: the summary, the subject
     * and the scores belong to the event, which is the card. A second copy of them per source would
     * be a payload three times the size saying the same thing.
     *
     * The shape is spelled out rather than left as `array<string, mixed>` because the generated
     * OpenAPI document is built from these annotations: without it the specification described
     * `sources` as an always-empty array of strings, which is a published contract telling a client
     * the opposite of the truth.
     *
     * @return array{id: int, sources_count: int, first_published_at: string|null, last_published_at: string|null, sources: list<array{id: int, source_name: string|null, source_url: string, published_at: string|null}>}|null
     */
    private function clusterPayload(Signal $signal): ?array
    {
        $cluster = $signal->relationLoaded('cluster') ? $signal->cluster : null;

        if (! $cluster instanceof SignalCluster || $cluster->sources_count < 2) {
            return null;
        }

        $sources = $cluster->relationLoaded('signals')
            // `array_values` after `values()` is not a belt-and-braces reindex — it is what makes
            // this a `list<…>` to the analyser, which a `Collection::all()` is not, and the shape
            // in the docblock is what the generated specification is built from.
            ? array_values($cluster->signals
                ->sortBy([['published_at', 'asc'], ['id', 'asc']])
                ->map(static fn (Signal $member): array => [
                    'id' => (int) $member->getKey(),
                    'source_name' => $member->source_name,
                    'source_url' => $member->source_url,
                    'published_at' => $member->published_at?->toIso8601String(),
                ])
                ->values()
                ->all())
            : [];

        return [
            'id' => (int) $cluster->getKey(),
            // How many articles reported this event, including this one. Always 2 or more: a
            // cluster of one is sent as a null `cluster`.
            'sources_count' => (int) $cluster->sources_count,
            'first_published_at' => $cluster->first_published_at?->toIso8601String(),
            'last_published_at' => $cluster->last_published_at?->toIso8601String(),
            'sources' => $sources,
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
