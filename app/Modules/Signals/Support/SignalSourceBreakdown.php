<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Models\SignalRun;

/**
 * What each source cost and found in one run (S13, task 8) — the accumulator behind
 * `signal_runs.source_breakdown`.
 *
 * **Why a class and not an array.** The run adds to this from three different places — once per
 * query executed, once per query the source rejected, and once at the end for the cost — and an
 * associative array built up across three loops is exactly where a key gets misspelled and a cost
 * lands on the wrong row in a figure nobody reconciles. The keys are enum values, the arithmetic is
 * in one place, and {@see self::toArray()} is the only shape that reaches the column.
 *
 * **It is a record, not a counter** — the distinction ADR 0012 drew for `usage_meters` and ADR 0022
 * drew for `score_breakdown`, one level down. Nothing increments this in the database; the run
 * computes it and writes it once, so it says what *that* run did at the prices of that day, and a
 * later change to `signals.sources.*.cost_micros_per_request` cannot rewrite history.
 *
 * `signal_runs.cost_micros` stays the single total and is what everything already reading a run's
 * cost keeps reading; this is the breakdown beneath it, and {@see self::totalCostMicros()} is
 * asserted against it in tests so the two cannot drift.
 */
final class SignalSourceBreakdown
{
    /**
     * @var array<string, array{queries: int, results: int, rejected: int, cost_micros: int}>
     */
    private array $rows = [];

    /**
     * This source's row, zeroed if it has none yet.
     *
     * @return array{queries: int, results: int, rejected: int, cost_micros: int}
     */
    private function row(SignalSourceKind $kind): array
    {
        return $this->rows[$kind->value] ?? [
            'queries' => 0,
            'results' => 0,
            'rejected' => 0,
            'cost_micros' => 0,
        ];
    }

    /**
     * Note that a query ran against a source and how many items it returned.
     *
     * Called per query rather than per source so a rejected one can be told from one that simply
     * found nothing — which is the difference between "your template is not accepted" and "there was
     * no news this week", and the two need different advice on screen.
     */
    public function recordQuery(SignalSourceKind $kind, int $results): void
    {
        $row = $this->row($kind);
        $row['queries']++;
        $row['results'] += max(0, $results);
        $this->rows[$kind->value] = $row;
    }

    /**
     * Note that a source refused a query (ADR 0023 §4).
     *
     * Counted under `rejected` and **not** under `queries`: the run did not get an answer, and
     * including it in the query count would make the cost-per-query arithmetic on the admin screen
     * wrong. Whether a rejected query was billed is the provider's business; we did not use it.
     */
    public function recordRejection(SignalSourceKind $kind): void
    {
        $row = $this->row($kind);
        $row['rejected']++;
        $this->rows[$kind->value] = $row;
    }

    /**
     * Add a source's metered cost, as the source itself computes it.
     *
     * Taken from `SignalSourceProvider::estimatedCostMicros()` rather than multiplied here, because
     * a source knows its own pricing — a free one returns zero, and a future one may not bill
     * per query at all.
     */
    public function recordCost(SignalSourceKind $kind, int $costMicros): void
    {
        $row = $this->row($kind);
        $row['cost_micros'] += max(0, $costMicros);
        $this->rows[$kind->value] = $row;
    }

    /** How many queries actually ran against a source. */
    public function queriesFor(SignalSourceKind $kind): int
    {
        return $this->rows[$kind->value]['queries'] ?? 0;
    }

    /** How many of a source's queries it refused. */
    public function rejectionsFor(SignalSourceKind $kind): int
    {
        return $this->rows[$kind->value]['rejected'] ?? 0;
    }

    /**
     * The sources that were asked something and answered **nothing** (S13a review finding 1).
     *
     * "Refused every query" is a different statement from "refused a query", and the run treats them
     * differently: the first is systemic and fails the run, the second is a note on this breakdown.
     * See `RunSignalWatchAction::assertSourcesAnswered()` for why.
     *
     * A source with no rejections and no queries is **not** included: it was never asked, which is
     * what a definition that names it while contributing no templates produces.
     *
     * @return list<SignalSourceKind>
     */
    public function sourcesThatAnsweredNothing(): array
    {
        $out = [];

        foreach ($this->rows as $key => $row) {
            $kind = SignalSourceKind::parse($key);

            if ($kind instanceof SignalSourceKind && $row['queries'] === 0 && $row['rejected'] > 0) {
                $out[] = $kind;
            }
        }

        return $out;
    }

    /**
     * How many queries this run actually **made** — answered plus refused, across every source.
     *
     * What `signal_runs.queries_count` is set from since the S13a review, which found the two
     * disagreeing: the column was the *planned* count and so included refused queries and queries
     * skipped for an unregistered source, while this breakdown counted only the answered ones. The
     * admin screen showed both and they did not sum. A query that was refused **was** made (it just
     * got no answer); a query skipped because its source is not registered was not, and is counted
     * nowhere. `queries_requested` remains the separate "what the plan wanted before the ceiling"
     * figure (ADR 0019 §4).
     */
    public function totalQueriesAttempted(): int
    {
        return array_sum(array_column($this->rows, 'queries'))
            + array_sum(array_column($this->rows, 'rejected'));
    }

    /**
     * The total metered cost across every source — what `signal_runs.cost_micros` must agree with
     * for the search half of a run.
     */
    public function totalCostMicros(): int
    {
        return array_sum(array_column($this->rows, 'cost_micros'));
    }

    /** The total items returned across every source. */
    public function totalResults(): int
    {
        return array_sum(array_column($this->rows, 'results'));
    }

    /** Whether anything was recorded at all; an empty breakdown is stored as null, not as `{}`. */
    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * The column's shape: source key → its four figures, in enum declaration order.
     *
     * Ordered deliberately, so two runs of the same watch serialise their sources the same way and a
     * diff of two rows is readable.
     *
     * @return array<string, array{queries: int, results: int, rejected: int, cost_micros: int}>
     */
    public function toArray(): array
    {
        $out = [];

        foreach (SignalSourceKind::cases() as $kind) {
            if (isset($this->rows[$kind->value])) {
                $out[$kind->value] = $this->rows[$kind->value];
            }
        }

        return $out;
    }

    /**
     * Read a stored breakdown back for display, skipping anything unreadable.
     *
     * Defensive on every field because this is a json column that has been written by some earlier
     * build: a run from before S13 has null here, and a key for a source since withdrawn must render
     * as itself rather than crash a screen. Returns a plain list so a UI can render it without
     * knowing the enum.
     *
     * @return list<array{source: string, label: string, queries: int, results: int, rejected: int, cost_micros: int}>
     */
    public static function fromRun(SignalRun $run): array
    {
        $stored = $run->source_breakdown;

        if (! is_array($stored)) {
            return [];
        }

        $out = [];

        foreach ($stored as $key => $row) {
            if (! is_string($key) || ! is_array($row)) {
                continue;
            }

            $kind = SignalSourceKind::parse($key);

            $out[] = [
                'source' => $key,
                // A withdrawn or unknown key labels as itself rather than as nothing: an operator
                // reading a historical run needs to see that *something* spent money there.
                'label' => $kind?->label() ?? $key,
                'queries' => (int) ($row['queries'] ?? 0),
                'results' => (int) ($row['results'] ?? 0),
                'rejected' => (int) ($row['rejected'] ?? 0),
                'cost_micros' => (int) ($row['cost_micros'] ?? 0),
            ];
        }

        return $out;
    }
}
