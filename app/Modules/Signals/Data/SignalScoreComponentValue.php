<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Enums\SignalScoreComponent;
use App\Modules\Signals\Services\SignalScorer;
use Spatie\LaravelData\Data;

/**
 * One row of a signal's score breakdown (S12): what a component was worth, how much of the heat it
 * contributed, and the evidence behind it.
 *
 * **`value` is nullable and that is the whole design.** A component with nothing to judge — no
 * amount in the article, no history for the outlet, no active ICP — is *unknown*, and
 * {@see SignalScorer} leaves its weight out of **both** sides of the average rather than scoring
 * it zero. Scoring a signal down for being thinly reported would make the heat measure how much
 * Alima knows about an event instead of how much the event is worth, which is the mistake
 * `Scoring\Services\DeterministicScorer` already documents at length and this follows.
 *
 * `evidence` is grounded in the row, never model-written prose: "$40m, higher than 80% of the deals
 * you have seen", "4 outlets", "no amount given". It is what the card shows, and it is why the
 * breakdown is **stored** rather than recomputed on render — the card has to explain the score the
 * feed was actually ranked by, even after somebody retunes the weights.
 */
final class SignalScoreComponentValue extends Data
{
    private const int MAX_EVIDENCE = 160;

    public function __construct(
        public readonly SignalScoreComponent $component,
        /** 0–100, or null when there was nothing to judge. */
        public readonly ?int $value,
        public readonly int $weight,
        /** The share of the final heat this contributed, 0–100. Zero for an unknown component. */
        public readonly int $contribution,
        /** A translation key or a plain, row-derived string. Never a sentence from a model. */
        public readonly ?string $evidence = null,
        /**
         * Whether `evidence` is **Alima's own wording** — and so safe to pass through `t()` — or a
         * figure from the row, rendered as given.
         *
         * The same rule, and the same flag, that `SignalResource::$event_label_translatable`
         * already applies to a signal type's name (S7). It is here because the breakdown is
         * **stored**: an English sentence written into `score_breakdown` today would reach an
         * Arabic card untranslated for as long as that signal lives, and nothing in an English
         * build would ever show it. So the figures stay literal and the sentences — the reasons a
         * component could not be judged — are keys.
         */
        public readonly bool $translatable = false,
    ) {}

    /**
     * A component the scorer could not judge. Carries its reason, because "no amount given" is
     * information a member reading the panel wants — it tells them the article was thin, not that
     * the deal was small.
     */
    public static function unknown(
        SignalScoreComponent $component,
        int $weight,
        ?string $why = null,
        bool $translatable = false,
    ): self {
        return new self(
            component: $component,
            value: null,
            weight: $weight,
            contribution: 0,
            evidence: $why,
            translatable: $translatable,
        );
    }

    /**
     * A judged component, clamped to 0–100 and with its evidence bounded.
     *
     * The contribution is filled in by the scorer once it knows the assessed total, because a
     * share is only meaningful against the components that were actually weighed.
     */
    public static function of(
        SignalScoreComponent $component,
        int $value,
        int $weight,
        ?string $evidence = null,
        bool $translatable = false,
    ): self {
        return new self(
            component: $component,
            value: max(0, min(100, $value)),
            weight: $weight,
            contribution: 0,
            evidence: $evidence === null ? null : mb_substr($evidence, 0, self::MAX_EVIDENCE),
            translatable: $translatable,
        );
    }

    /** The same row with its share of the heat filled in. */
    public function contributing(int $contribution): self
    {
        return new self(
            component: $this->component,
            value: $this->value,
            weight: $this->weight,
            contribution: $contribution,
            evidence: $this->evidence,
            translatable: $this->translatable,
        );
    }

    /** Whether this component had anything to say. */
    public function isAssessed(): bool
    {
        return $this->value !== null;
    }
}
