<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The trend chart's week buckets, and the SQL that fills them in one round trip.
 *
 * Weeks are cut in the **team's** timezone (Monday to Monday) and then compared as UTC instants,
 * because timestamps are stored UTC and displayed in the team timezone (CLAUDE.md §2). A Dubai team
 * and a London team therefore see their own weeks, not the server's.
 *
 * Bucketing is done with conditional aggregation — one `sum(case when … end)` column per week —
 * rather than a `group by` over a date expression, because `week()`/`strftime()` differ between
 * MySQL (production) and SQLite (tests). Plain comparisons against bound instants behave the same
 * on both, stay index-friendly on `created_at`, and cost one query instead of one per week.
 */
final class WeeklyBuckets
{
    /**
     * Aliases for the case columns, one per bucket.
     *
     * A hard-coded literal list, because `selectRaw()` only accepts a `literal-string` — that is
     * what stops a column name ever being assembled from request data — and `'w'.$index` is not
     * one however the integer was produced. Its length is therefore also the ceiling on how many
     * buckets a single query can carry, which {@see lastWeeks()} clamps to: a year of weeks, far
     * more than any chart in the product asks for.
     *
     * @var list<literal-string>
     */
    private const ALIASES = [
        'w00', 'w01', 'w02', 'w03', 'w04', 'w05', 'w06', 'w07', 'w08', 'w09', 'w10', 'w11', 'w12',
        'w13', 'w14', 'w15', 'w16', 'w17', 'w18', 'w19', 'w20', 'w21', 'w22', 'w23', 'w24', 'w25',
        'w26', 'w27', 'w28', 'w29', 'w30', 'w31', 'w32', 'w33', 'w34', 'w35', 'w36', 'w37', 'w38',
        'w39', 'w40', 'w41', 'w42', 'w43', 'w44', 'w45', 'w46', 'w47', 'w48', 'w49', 'w50', 'w51',
    ];

    /**
     * @param  list<array{label: string, starts_on: string, start: CarbonImmutable, end: CarbonImmutable}>  $buckets
     */
    private function __construct(public readonly array $buckets) {}

    /**
     * The `$weeks` most recent whole-and-current weeks, oldest first, ending with the week that
     * contains `now`. Clamped to one week at the bottom and {@see ALIASES} at the top.
     */
    public static function lastWeeks(int $weeks, string $timezone): self
    {
        $weeks = max(1, min(count(self::ALIASES), $weeks));

        // `->toImmutable()` is not decoration. The app configures the Date facade as immutable
        // (AppServiceProvider), but this class must not depend on that: against a mutable Carbon,
        // `subWeeks()` and `addWeek()` below would rewrite the same instant on every pass and the
        // whole chart would be wrong. Converting here makes that impossible rather than unlikely.
        $thisWeek = Date::now($timezone)->toImmutable()->startOfWeek();

        $buckets = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start = $thisWeek->subWeeks($i);
            $end = $start->addWeek();

            $buckets[] = [
                'label' => $start->format('j M'),
                'starts_on' => $start->toDateString(),
                'start' => $start,
                'end' => $end,
            ];
        }

        return new self($buckets);
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
     * The window bounds for {@see countSelect()}, in the order the case columns consume them. UTC,
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
     * Read the aliased columns back off a result row into a plain list, oldest week first.
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
}
