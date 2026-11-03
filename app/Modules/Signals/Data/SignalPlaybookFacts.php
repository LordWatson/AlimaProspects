<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalPlaybookEvaluator;

/**
 * Everything {@see SignalPlaybookEvaluator} is allowed to know about a signal (S14).
 *
 * The evaluator takes this rather than a {@see Signal}, and the reason is the one the phase plan
 * asks for: it has to be **pure, deterministic and fully testable against a table of fixtures**. A
 * matcher handed an Eloquent model is a matcher that can lazily load a relation, read a column
 * somebody added, or behave differently inside a team context — none of which a condition fixture
 * can reproduce. Nine scalars can.
 *
 * It is also the list of what a team may condition on, in one place. Adding a condition means
 * adding a field here and a rule in {@see SignalPlaybookConditions}, which is a small enough change
 * to make deliberately.
 */
final readonly class SignalPlaybookFacts
{
    public function __construct(
        /** The type that classified it, when the definition still exists. */
        public ?int $definitionId,
        /** The definition's key, copied onto the signal, which survives the definition. */
        public string $eventKey,
        /** A `SignalSourceKind` value. */
        public string $sourceKind,
        /** ISO-3166-1 alpha-2, upper case, or null when the place could not be resolved. */
        public ?string $jurisdiction,
        /** 0-100, or **null for "not scored"** — which is not zero (ADR 0022). */
        public ?int $heat,
        /** Minor units, or null when the article named no amount. */
        public ?int $amountMinor,
        /** Whether Alima matched the event to one of the team's leads (S10). */
        public bool $hasMatchedLead,
        /** The watchlist the watch was pointed at, when it was pointed at one. */
        public ?int $watchlistId,
        /**
         * Whether this row stands for the event rather than for one outlet's coverage of it (S11).
         * A member never actions a cluster member, so neither does a rule.
         */
        public bool $isClusterRepresentative,
    ) {}

    /**
     * Read the facts off a signal.
     *
     * Reads **only loaded relations**: `cluster` through the model's own
     * {@see Signal::isClusterRepresentative()}, and the watch's watchlist id through the relation
     * when the caller eager-loaded it. A lazy read here would trip `preventLazyLoading` in exactly
     * the place it is most useful — a queued job walking two hundred signals.
     */
    public static function fromSignal(Signal $signal): self
    {
        $watch = $signal->relationLoaded('watch') ? $signal->watch : null;

        return new self(
            definitionId: $signal->signal_definition_id === null ? null : (int) $signal->signal_definition_id,
            eventKey: $signal->event_key,
            sourceKind: $signal->source_kind->value,
            // Upper-cased here rather than trusted: the column is written by the extractor and by
            // the structured sources, and a condition that matched "gb" but not "GB" would be a bug
            // nobody could see on the screen.
            jurisdiction: $signal->jurisdiction === null ? null : strtoupper($signal->jurisdiction),
            heat: $signal->heat === null ? null : (int) $signal->heat,
            amountMinor: $signal->amount_minor === null ? null : (int) $signal->amount_minor,
            hasMatchedLead: $signal->matched_lead_id !== null,
            watchlistId: $watch instanceof SignalWatch && $watch->signal_watchlist_id !== null
                ? (int) $watch->signal_watchlist_id
                : null,
            isClusterRepresentative: $signal->isClusterRepresentative(),
        );
    }
}
