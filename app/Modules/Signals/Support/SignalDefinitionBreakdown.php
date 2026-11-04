<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

use App\Modules\Signals\Services\SignalSpendLedger;

/**
 * What each signal **type** cost and found in one run (S15 task 2) — the accumulator behind
 * `signal_definition_spend`.
 *
 * The deliberate mirror of {@see SignalSourceBreakdown}, which answers the same question about
 * sources, and for the same reasons: the run adds to this from two places in a loop, and an
 * associative array built up across a loop is where a key gets misspelled and a cost lands on the
 * wrong row in a figure nobody reconciles.
 *
 * **The two cost halves are kept apart because they are different kinds of figure**, and that
 * distinction is the whole reason this class is not just a map of totals:
 *
 * - The **search** cost is measured. A query names exactly one type (`SignalSourceQuery::$eventKey`),
 *   so attributing what it cost needs no judgement.
 * - The **AI** cost is allocated. One classification call is offered every type on the watch at
 *   once — that sharing is where S11's saving comes from — so there is no measurement to make.
 *   {@see self::allocateAi()} apportions the run's AI cost by each type's share of the results it
 *   classified, which is a defensible allocation and still an allocation.
 *
 * Nothing here reads a definition row: the key is the type's `event_key`, exactly as a signal
 * stores it, so a type deleted between the run and the read still has a cost history (ADR 0018 §4).
 *
 * {@see SignalSpendLedger} is what turns this into rows.
 */
final class SignalDefinitionBreakdown
{
    /**
     * @var array<string, array{queries: int, results: int, search_cost_micros: int, ai_cost_micros: int}>
     */
    private array $rows = [];

    /** Whether the run's AI cost has already been apportioned. See {@see self::allocateAi()}. */
    private bool $allocated = false;

    /**
     * Note that a query ran for a type and how many items it returned.
     *
     * Only an **answered** query is recorded, which is the same rule the source breakdown follows:
     * a refused or skipped query bought nothing, and counting it would make the cost-per-query
     * arithmetic on the ROI card wrong.
     */
    public function recordQuery(string $eventKey, int $results): void
    {
        $row = $this->row($eventKey);
        $row['queries']++;
        $row['results'] += max(0, $results);
        $this->rows[$eventKey] = $row;
    }

    /**
     * Note what a type's queries cost.
     *
     * Taken in one call per (type, source) pair rather than per query, so a provider's
     * `estimatedCostMicros()` still sees a count it can price non-linearly if it ever needs to —
     * the arrangement the source breakdown already has.
     */
    public function recordSearchCost(string $eventKey, int $costMicros): void
    {
        $row = $this->row($eventKey);
        $row['search_cost_micros'] += max(0, $costMicros);
        $this->rows[$eventKey] = $row;
    }

    /**
     * Apportion the run's AI cost across the types by their share of the results.
     *
     * By **raw results**, and the word matters: these are the items each type's queries returned,
     * not the items that reached the classifier. The batch is smaller — `dropAlreadySeen` and
     * `dropRejected` take most of it on a steady-state watch, and an article found by two types is
     * classified once. So this is an allocation **by volume brought to the run**, which is a
     * defensible basis and not the same as "share of what was classified" (review). Weighting by
     * the batch itself would be closer, and would need the run to tell the breakdown which items
     * survived which filter — attributing a shared article to one of its two finders, which has no
     * right answer either.
     *
     * Not by **queries**: a type whose four searches returned nothing brought nothing to classify,
     * and charging it a quarter of the call would make an empty type look expensive.
     *
     * A run with results but no AI cost (everything was already known, or the clustering skipped
     * the call) allocates nothing, which is correct. A run with an AI cost and **no** results at
     * all cannot have classified anything, so there is nothing to apportion it to and it stays
     * where it is, on `signal_runs.cost_micros` — the run total is always the whole truth, and this
     * is always the breakdown beneath it.
     *
     * The remainder from integer division goes to the largest contributor rather than being
     * dropped, so the parts sum to the whole. A figure that quietly loses a few micros per run is
     * one nobody can reconcile with the run total, which is exactly the complaint this table
     * exists to answer.
     */
    public function allocateAi(int $costMicros): void
    {
        if ($this->allocated) {
            // **Once per run.** Every caller in the run calls it exactly once today; the guard is
            // here because the failure mode is silent — a second call adds again and the breakdown
            // stops reconciling against `signal_runs.cost_micros`, which is the one thing this
            // class exists to make possible.
            return;
        }

        $this->allocated = true;

        $totalResults = 0;

        foreach ($this->rows as $row) {
            $totalResults += $row['results'];
        }

        if ($costMicros < 1 || $totalResults < 1) {
            return;
        }

        $allocated = 0;
        $largestKey = null;
        $largestResults = -1;

        foreach ($this->rows as $key => $row) {
            $share = intdiv($costMicros * $row['results'], $totalResults);
            $this->rows[$key]['ai_cost_micros'] += $share;
            $allocated += $share;

            if ($row['results'] > $largestResults) {
                $largestResults = $row['results'];
                $largestKey = $key;
            }
        }

        if ($largestKey !== null && $allocated < $costMicros) {
            $this->rows[$largestKey]['ai_cost_micros'] += $costMicros - $allocated;
        }
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Every type's row, keyed by `event_key`.
     *
     * @return array<string, array{queries: int, results: int, search_cost_micros: int, ai_cost_micros: int}>
     */
    public function toArray(): array
    {
        return $this->rows;
    }

    /**
     * The total this breakdown accounts for, asserted in tests against `signal_runs.cost_micros`
     * so the two cannot drift.
     */
    public function totalCostMicros(): int
    {
        $total = 0;

        foreach ($this->rows as $row) {
            $total += $row['search_cost_micros'] + $row['ai_cost_micros'];
        }

        return $total;
    }

    /**
     * @return array{queries: int, results: int, search_cost_micros: int, ai_cost_micros: int}
     */
    private function row(string $eventKey): array
    {
        return $this->rows[$eventKey] ?? [
            'queries' => 0,
            'results' => 0,
            'search_cost_micros' => 0,
            'ai_cost_micros' => 0,
        ];
    }
}
