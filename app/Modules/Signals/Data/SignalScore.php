<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Enums\SignalScoreComponent;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Services\SignalScorer;
use Spatie\LaravelData\Data;

/**
 * A signal's heat and the breakdown that produced it (S12), as {@see SignalScorer} returns it and
 * as `signals.heat` / `signals.score_breakdown` store it.
 *
 * The heat is `contributed / assessed`, so it is a **normalised** 0–100: an untuned definition and
 * one with half its weights zeroed sit on the same scale, and nobody can lift a signal by adding
 * components. {@see self::$assessed} is how much weight had anything to say — when it is zero the
 * heat is zero *and meaningless*, and {@see self::isMeaningful()} is what a caller checks before
 * presenting it as a judgement.
 */
final class SignalScore extends Data
{
    /**
     * @param  list<SignalScoreComponentValue>  $components
     */
    public function __construct(
        public readonly int $heat,
        public readonly array $components = [],
        /** Total weight of the components that could be judged. */
        public readonly int $assessed = 0,
    ) {}

    /**
     * Whether anything could be weighed at all.
     *
     * False for a signal with no relevance, no confidence, no date and no subject — which should
     * not happen, because the first two are not nullable columns, but a caller presenting a heat
     * of zero as "we judged this and it is worthless" when the truth is "we could not judge it" is
     * the one lie this class exists to prevent.
     */
    public function isMeaningful(): bool
    {
        return $this->assessed > 0;
    }

    /**
     * The breakdown as `signals.score_breakdown` holds it: a plain array, components in the order
     * the scorer weighed them.
     *
     * Stored as JSON rather than a relation because it is read **with** the signal every time and
     * on its own never — the same reasoning `lead_scores.breakdown` follows.
     *
     * @return list<array<string, mixed>>
     */
    public function toBreakdown(): array
    {
        return array_map(
            static fn (SignalScoreComponentValue $component): array => [
                'component' => $component->component->value,
                'value' => $component->value,
                'weight' => $component->weight,
                'contribution' => $component->contribution,
                'evidence' => $component->evidence,
                'evidence_translatable' => $component->translatable,
            ],
            $this->components,
        );
    }

    /**
     * Read a stored breakdown back, dropping anything unrecognised.
     *
     * Unrecognised rather than rejected, deliberately: a component removed in a later release must
     * not make every signal scored before it unreadable, and a card that renders seven rows
     * instead of eight is a better outcome than one that throws. The same forgiveness
     * {@see SignalFilters} applies to a stale bookmark.
     *
     * @param  array<array-key, mixed>  $breakdown
     * @return list<SignalScoreComponentValue>
     */
    public static function componentsFrom(array $breakdown): array
    {
        $components = [];

        foreach ($breakdown as $row) {
            if (! is_array($row)) {
                continue;
            }

            $component = SignalScoreComponent::tryFrom(
                is_string($row['component'] ?? null) ? $row['component'] : '',
            );

            if ($component === null) {
                continue;
            }

            $value = $row['value'] ?? null;

            $components[] = new SignalScoreComponentValue(
                component: $component,
                value: is_numeric($value) ? (int) $value : null,
                weight: is_numeric($row['weight'] ?? null) ? (int) $row['weight'] : 0,
                contribution: is_numeric($row['contribution'] ?? null) ? (int) $row['contribution'] : 0,
                evidence: is_string($row['evidence'] ?? null) ? $row['evidence'] : null,
                // Absent on a breakdown written before the flag existed, which reads as "a figure,
                // render it as given" — the right default, because the sentences it would have
                // wanted to translate are English either way and a missing key renders as itself.
                translatable: (bool) ($row['evidence_translatable'] ?? false),
            );
        }

        return $components;
    }

    /**
     * The breakdown of a {@see Signal} that has one, or an empty list.
     *
     * @return list<SignalScoreComponentValue>
     */
    public static function forSignal(Signal $signal): array
    {
        return self::componentsFrom($signal->score_breakdown ?? []);
    }
}
