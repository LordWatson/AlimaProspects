<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use App\Modules\Reporting\Enums\TrendGranularity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The trend chart's buckets, and the SQL that fills them in one round trip.
 *
 * Buckets are cut in the **team's** timezone and then compared as UTC instants, because timestamps
 * are stored UTC and displayed in the team timezone (CLAUDE.md §2). A Dubai team and a London team
 * therefore see their own days and weeks, not the server's.
 *
 * How wide a bucket is comes from the selected window, not from configuration — see
 * {@see TrendGranularity::for()}. The first and last buckets are **clamped to the window**, so the
 * bars add up to the same total the funnel and the KPI tiles report for that window; a half-week
 * hanging off the front of a "last 30 days" filter would quietly disagree with every other figure
 * on the screen.
 *
 * Bucketing is done with conditional aggregation — one `sum(case when … end)` column per bucket —
 * rather than a `group by` over a date expression, because `week()`/`strftime()` differ between
 * MySQL (production) and SQLite (tests). Plain comparisons against bound instants behave the same
 * on both, stay index-friendly on `created_at`, and cost one query instead of one per bucket.
 */
final class TrendBuckets
{
    /**
     * Aliases for the case columns, one per bucket.
     *
     * A hard-coded literal list, because `selectRaw()` only accepts a `literal-string` — that is
     * what stops a column name ever being assembled from request data — and `'b'.$index` is not
     * one however the integer was produced. Its length is therefore also the ceiling on how many
     * buckets a single query can carry, which this class clamps to by dropping the **oldest**
     * buckets: a chart that cannot show everything should show the recent end of it.
     *
     * @var list<literal-string>
     */
    private const ALIASES = [
        'b00', 'b01', 'b02', 'b03', 'b04', 'b05', 'b06', 'b07', 'b08', 'b09', 'b10', 'b11', 'b12',
        'b13', 'b14', 'b15', 'b16', 'b17', 'b18', 'b19', 'b20', 'b21', 'b22', 'b23', 'b24', 'b25',
        'b26', 'b27', 'b28', 'b29', 'b30', 'b31', 'b32', 'b33', 'b34', 'b35', 'b36', 'b37', 'b38',
        'b39', 'b40', 'b41', 'b42', 'b43', 'b44', 'b45', 'b46', 'b47', 'b48', 'b49', 'b50', 'b51',
    ];

    /**
     * @param  list<array{label: string, starts_on: string, start: CarbonImmutable, end: CarbonImmutable}>  $buckets
     */
    private function __construct(
        public readonly array $buckets,
        public readonly TrendGranularity $granularity,
    ) {}

    /**
     * Cut `$range` into buckets, oldest first.
     *
     * An **open-ended** window (all time) has no length to divide, so it falls back to the most
     * recent `$fallbackWeeks` weeks. That is the one place where the trend shows less than the rest
     * of the dashboard, and the chart says so in its own subtitle.
     */
    public static function forRange(DateRange $range, int $fallbackWeeks = 12): self
    {
        $granularity = $range->granularity();

        if ($range->from === null || $range->to === null) {
            return self::lastPeriods(TrendGranularity::Week, $fallbackWeeks, $range->timezone);
        }

        $buckets = [];
        $cursor = $granularity->startOf($range->from);

        while ($cursor->lessThan($range->to)) {
            $next = $granularity->next($cursor);

            $buckets[] = self::bucket(
                $granularity,
                $cursor,
                // Clamped to the window at both ends: the chart's total must equal the window's.
                $cursor->lessThan($range->from) ? $range->from : $cursor,
                $next->greaterThan($range->to) ? $range->to : $next,
            );

            $cursor = $next;
        }

        if ($buckets === []) {
            $buckets = [self::bucket($granularity, $range->from, $range->from, $range->to)];
        }

        return new self(self::clamp($buckets), $granularity);
    }

    /**
     * The `$periods` most recent whole-and-current periods, ending with the one that contains
     * *now*. The shape the dashboard used before there was a filter, and still what an open-ended
     * window gets.
     */
    public static function lastPeriods(
        TrendGranularity $granularity,
        int $periods,
        string $timezone,
    ): self {
        $periods = max(1, min(count(self::ALIASES), $periods));

        // `toImmutable()` is not decoration. The app configures the Date facade as immutable
        // (AppServiceProvider), but this class must not depend on that: against a mutable Carbon,
        // the arithmetic below would rewrite the same instant on every pass and the whole chart
        // would be wrong. Converting here makes that impossible rather than unlikely.
        $current = $granularity->startOf(Date::now($timezone)->toImmutable());

        $starts = [];
        $cursor = $current;

        for ($i = 0; $i < $periods; $i++) {
            $starts[] = $cursor;
            $cursor = self::previousStart($granularity, $cursor);
        }

        $buckets = [];

        foreach (array_reverse($starts) as $start) {
            $buckets[] = self::bucket($granularity, $start, $start, $granularity->next($start));
        }

        return new self($buckets, $granularity);
    }

    /**
     * The first instant the window covers — a cheap `where` to put in front of the case columns so
     * the database never scans rows older than the chart.
     */
    public function from(): CarbonImmutable
    {
        return $this->buckets[0]['start'];
    }

    /**
     * A conditional-aggregation select over `$column` (qualify it, e.g. `leads.created_at`), one
     * aliased column per bucket. Pair with {@see bindings()}.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public function countSelect(string $column): string
    {
        /** @var list<literal-string> $parts */
        $parts = [];

        foreach (array_keys($this->buckets) as $index) {
            $parts[] = 'sum(case when '.$column.' >= ? and '.$column
                .' < ? then 1 else 0 end) as '.self::ALIASES[$index];
        }

        return implode(', ', $parts);
    }

    /**
     * The bucket bounds for {@see countSelect()}, in the order the case columns consume them. UTC,
     * because that is how the timestamps are stored.
     *
     * @return list<string>
     */
    public function bindings(): array
    {
        $bindings = [];

        foreach ($this->buckets as $bucket) {
            $bindings[] = $bucket['start']->utc()->toDateTimeString();
            $bindings[] = $bucket['end']->utc()->toDateTimeString();
        }

        return $bindings;
    }

    /**
     * Read the aliased columns back off a result row into a plain list, oldest bucket first.
     *
     * @param  array<string, mixed>  $row
     * @return list<int>
     */
    public function readRow(array $row): array
    {
        $values = [];

        foreach (array_keys($this->buckets) as $index) {
            $values[] = (int) ($row[self::ALIASES[$index]] ?? 0);
        }

        return $values;
    }

    public function count(): int
    {
        return count($this->buckets);
    }

    /**
     * @return array{label: string, starts_on: string, start: CarbonImmutable, end: CarbonImmutable}
     */
    private static function bucket(
        TrendGranularity $granularity,
        CarbonImmutable $period,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        return [
            // Labelled and keyed by the **period** it represents, not by the clamped start: a bar
            // for the week of the 5th is the week of the 5th even when the filter opened on the 7th.
            'label' => $period->format($granularity->axisFormat()),
            'starts_on' => $period->toDateString(),
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Step one period back. Months use `NoOverflow` so stepping back from 31 March lands in
     * February rather than skipping it.
     */
    private static function previousStart(
        TrendGranularity $granularity,
        CarbonImmutable $start,
    ): CarbonImmutable {
        return match ($granularity) {
            TrendGranularity::Day => $start->subDay(),
            TrendGranularity::Week => $start->subWeek(),
            TrendGranularity::Month => $start->subMonthNoOverflow()->startOfMonth(),
        };
    }

    /**
     * Drop the oldest buckets past the alias ceiling. Only reachable for a monthly window longer
     * than {@see ALIASES} allows — four years and change — where showing the recent end is a
     * better answer than refusing to draw the chart.
     *
     * @param  list<array{label: string, starts_on: string, start: CarbonImmutable, end: CarbonImmutable}>  $buckets
     * @return list<array{label: string, starts_on: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private static function clamp(array $buckets): array
    {
        return count($buckets) <= count(self::ALIASES)
            ? $buckets
            : array_slice($buckets, -count(self::ALIASES));
    }
}
