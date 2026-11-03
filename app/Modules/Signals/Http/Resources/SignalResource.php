<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Actions\ListSignalsAction;
use App\Modules\Signals\Data\SignalScore;
use App\Modules\Signals\Data\SignalScoreComponentValue;
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
 * **`explanation` is the one addition that is not a field** (S12): it is the card's "why you're
 * seeing this" panel — the stored score breakdown, the type and version that classified it, the
 * watch that found it, and the jurisdiction that decides how it may be approached. Built from the
 * **stored** breakdown rather than recomputed, so the panel explains the score the feed was ranked
 * by and not what today's weights would say about yesterday's order.
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
            // Which source found it, and the query that did (S13). The kind is always present —
            // every pre-S13 signal is news, correctly — and `matched_template` is nullable because
            // a source that does not search by keyword has no template behind it.
            'source_kind' => $signal->source_kind->value,
            'source_kind_label' => $signal->source_kind->label(),
            'matched_template' => $signal->matched_template,
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
            // How much this event is worth to this team, 0-100 (S12), or null on a signal found
            // before the scorer existed and not yet re-scored. Null rather than zero, because the
            // feed's heat sort has to be able to tell "not judged" from "judged and cold".
            'heat' => $signal->heat,
            // **Why you're seeing this**: the score's own working, the type that classified it, the
            // place and what that means for the approach. The phase's actual deliverable — a
            // ranking nobody can interrogate is worse than date order — and, not incidentally, the
            // fastest debugging tool the people supporting this will ever get.
            'explanation' => $this->explanation($signal),
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
     * Everything a member needs to decide whether to trust this card's place in the feed (S12).
     *
     * **Read from the stored breakdown, not recomputed.** That is the difference between an
     * explanation and a guess: the panel has to explain the score the feed was actually *ranked*
     * by, and re-scoring on render would describe today's weights against a row ordered by
     * yesterday's. It is also why `signals.score_breakdown` is a column rather than a derivation.
     *
     * Null when the signal has never been scored, which the card reads as "no panel" rather than
     * as an empty one — an explanation with nothing in it is worse than the honest absence of one.
     *
     * Every string here is either a translation key or **evidence taken from the row**: a count, a
     * percentile, the outlet's own name. Nothing in it is model-written prose, which is the same
     * rule `Scoring\Data\CriterionScore::$evidence` follows and the reason either can be trusted.
     *
     * @return array<string, mixed>|null
     */
    private function explanation(Signal $signal): ?array
    {
        $components = SignalScore::forSignal($signal);

        if ($signal->heat === null && $components === []) {
            return null;
        }

        $definition = $signal->relationLoaded('definition') ? $signal->definition : null;
        $watch = $signal->relationLoaded('watch') ? $signal->watch : null;

        return [
            'heat' => $signal->heat,
            // The rows, in the order the scorer weighed them, with the unjudged ones **kept**: a
            // panel that silently dropped them would read as a shorter list rather than as
            // "Alima could not tell, and here is why".
            'components' => array_map(
                static fn (SignalScoreComponentValue $component): array => [
                    'key' => $component->component->value,
                    'label' => $component->component->label(),
                    'explanation' => $component->component->explanation(),
                    'value' => $component->value,
                    'weight' => $component->weight,
                    'contribution' => $component->contribution,
                    'evidence' => $component->evidence,
                    // Whether the card may pass `evidence` through `t()`. Figures are rendered as
                    // given; Alima's own wording is translated. Same flag, same reason, as a
                    // signal type's name (S7).
                    'evidence_translatable' => $component->translatable,
                    'assessed' => $component->isAssessed(),
                ],
                $components,
            ),
            // Which type decided this was an event, and which version of it — so a member who
            // retuned a type yesterday can tell whether this card predates the change. The key is
            // the fallback for a type that has since been deleted (ADR 0018 §4).
            'definition' => [
                'id' => $signal->signal_definition_id,
                'key' => $signal->event_key,
                'name' => $signal->eventLabel(),
                'name_translatable' => $this->labelIsOurs($signal),
                'version' => $definition instanceof SignalDefinition ? $definition->version : null,
            ],
            // Which watch found it. Plumbing on the card itself (the S5 brand review took the
            // "found by" row off), but exactly what somebody debugging a feed wants first.
            'watch' => $watch === null ? null : ['id' => $watch->id, 'name' => $watch->name],
            // Where the subject is and what follows from it. The restriction is the part that
            // matters: a member reading why this card is here should also be reading how they are
            // allowed to approach it (S8, ADR 0019).
            'jurisdiction' => $signal->jurisdiction,
            'resolved_market' => $signal->resolved_market,
            // Whether the full article was read, or only the headline and snippet (S12). A member
            // judging an amount deserves to know which.
            'read_full_article' => $signal->article_fetched_at !== null,
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
