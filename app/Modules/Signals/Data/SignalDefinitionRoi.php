<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Services\SignalRoiReader;

/**
 * What one signal type has done and cost over a window (S15 task 2).
 *
 * The figures a team needs to answer the only question that matters about a type they are paying to
 * run: **is it worth it?** Found → kept → converted → reached the pipeline, beside what it cost and
 * what each converted lead cost.
 *
 * The funnel's definition is deliberately the same one S12's performance report uses, because two
 * screens disagreeing about what "converted" means is worse than either number being imperfect:
 * counted per **event** rather than per article (one cluster is one story), scoped by when the
 * signal was **found** rather than by when the lead moved, and "reached the pipeline" meaning *off
 * the default column of the team's own board* rather than any outcome of ours.
 *
 * **`costPerConvertedMicros` is null rather than zero when nothing converted**, and that is the
 * whole point of the type. A zero would read as "free", when the truth is that the money bought no
 * leads at all — which is precisely the case a team needs to see. The screen renders the cost and
 * the words "no leads yet" instead of a number.
 *
 * Built by {@see SignalRoiReader}.
 */
final readonly class SignalDefinitionRoi
{
    public function __construct(
        /** The type's own key, which survives the definition (ADR 0018 §4). */
        public string $eventKey,
        public int $days,
        /** Events found, counted per cluster representative. */
        public int $found,
        /** Found and not dismissed. */
        public int $kept,
        public int $converted,
        /** Converted **and** moved off the default column of the team's board. */
        public int $progressed,
        /** Measured: a query belongs to exactly one type. */
        public int $searchCostMicros,
        /** Allocated by share of results: one classification call serves every type at once. */
        public int $aiCostMicros,
        public int $queries,
    ) {}

    public function totalCostMicros(): int
    {
        return $this->searchCostMicros + $this->aiCostMicros;
    }

    /** 0.0–1.0, or null when nothing was found — a rate over nothing is not zero. */
    public function keptRate(): ?float
    {
        return $this->found < 1 ? null : $this->kept / $this->found;
    }

    public function convertedRate(): ?float
    {
        return $this->found < 1 ? null : $this->converted / $this->found;
    }

    /**
     * What each converted lead cost, or **null when none did**. See the class note: null is the
     * answer, not zero.
     */
    public function costPerConvertedMicros(): ?int
    {
        return $this->converted < 1 ? null : intdiv($this->totalCostMicros(), $this->converted);
    }

    /**
     * Whether this type has done enough for its figures to mean anything.
     *
     * A type with four signals and none kept is not evidence of anything; a type with eighty is.
     * The floor is what keeps the retirement suggestion (task 3) from being an opinion, and it is
     * asked here so the screen and the advisor cannot disagree about it.
     */
    public function hasEnoughHistory(int $minimum): bool
    {
        return $this->found >= max(1, $minimum);
    }

    /**
     * The shape the library screen and the API read. Money in minor units as everywhere; rates as
     * fractions, so the client formats them in its own locale.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'event_key' => $this->eventKey,
            'days' => $this->days,
            'found' => $this->found,
            'kept' => $this->kept,
            'converted' => $this->converted,
            'progressed' => $this->progressed,
            'queries' => $this->queries,
            'kept_rate' => $this->keptRate(),
            'converted_rate' => $this->convertedRate(),
            // Split, because one is measured and the other allocated — a single total would
            // present an allocation as a measurement.
            'search_cost_micros' => $this->searchCostMicros,
            'ai_cost_micros' => $this->aiCostMicros,
            'cost_micros' => $this->totalCostMicros(),
            'cost_per_converted_micros' => $this->costPerConvertedMicros(),
        ];
    }
}
