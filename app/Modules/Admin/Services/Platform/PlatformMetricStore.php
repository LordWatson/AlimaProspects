<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Admin\Enums\PlatformMetric;
use App\Modules\Admin\Models\PlatformDailyMetric;
use App\Modules\Reporting\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Everything that reads or writes `platform_daily_metrics` (Phase 19c).
 *
 * One door, for three reasons that are each a bug somewhere else in this product's history:
 *
 * 1. **The window has to reach a date column as dates.** {@see DateRange::constrain()} is right
 *    for an *instant* column and wrong here. Its upper bound is an exclusive instant, and a
 *    `DATE` column compared against `2026-11-01 00:00:00` matches `2026-11-01` on both MySQL and
 *    SQLite — so the day *after* the window would be counted, every time, in the one direction
 *    nobody checks. {@see self::constrain()} converts the bounds to UTC and then to whole days,
 *    which is the only comparison a date column can answer correctly.
 * 2. **A snapshot must not be summed.** Thirty days of MRR summed is thirty months of revenue.
 *    {@see PlatformMetric::isSnapshot()} decides, and it decides *here*, so no builder can get it
 *    wrong on its own.
 * 3. **A recompute has to be able to drop a dimension.** Writing a day's rows with an upsert alone
 *    leaves yesterday's `google_places` row behind when today's recompute finds no Google Places
 *    calls — and the figure stays on the screen for ever. {@see self::replaceDay()} deletes the
 *    metric's rows for the day and writes the new set in one transaction, which is what makes the
 *    rollup genuinely idempotent rather than merely repeatable.
 */
final class PlatformMetricStore
{
    /**
     * Replace one metric's rows for one day.
     *
     * `$byDimension` maps dimension to value; `['' => 4]` is the undimensioned total. An empty map
     * clears the day, which is correct and is the case an upsert cannot express.
     *
     * Zeros are **written**, not skipped. "No AI calls failed yesterday" and "we have not rolled
     * yesterday up yet" are different statements, and a reader that cannot tell them apart will
     * eventually read the second as the first.
     *
     * @param  array<string, int>  $byDimension
     */
    public function replaceDay(CarbonImmutable $date, PlatformMetric $metric, array $byDimension): void
    {
        $day = $date->utc()->toDateString();

        DB::transaction(function () use ($day, $metric, $byDimension): void {
            PlatformDailyMetric::query()
                ->where('date', $day)
                ->where('metric', $metric->value)
                ->delete();

            if ($byDimension === []) {
                return;
            }

            $now = CarbonImmutable::now('UTC');

            $rows = array_map(
                static fn (string $dimension, int $value): array => [
                    'date' => $day,
                    'metric' => $metric->value,
                    // Truncated to the column's width. A model name or a prompt key is a
                    // free-form string, and MySQL in strict mode would reject the whole day's
                    // insert over one long one — losing every other dimension with it.
                    'dimension' => mb_substr($dimension, 0, 191),
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                array_keys($byDimension),
                array_values($byDimension),
            );

            // Chunked, because `TeamMrr` and `TeamCostMicros` write one row per tenant and MySQL
            // binds at most 65,535 placeholders per statement — six columns a row puts the ceiling
            // at about 10,900 tenants, which is a number this product intends to pass.
            foreach (array_chunk($rows, 1_000) as $chunk) {
                PlatformDailyMetric::query()->insert($chunk);
            }
        });
    }

    /**
     * One point, for the rollup's own look-back at yesterday — MRR movement is today's per-tenant
     * MRR against the row this wrote last night.
     */
    public function valueOn(CarbonImmutable $date, PlatformMetric $metric, string $dimension = ''): int
    {
        return (int) PlatformDailyMetric::query()
            ->where('date', $date->utc()->toDateString())
            ->where('metric', $metric->value)
            ->where('dimension', $dimension)
            ->value('value');
    }

    /**
     * Every dimension of one metric on one day.
     *
     * @return array<string, int>
     */
    public function dimensionsOn(CarbonImmutable $date, PlatformMetric $metric): array
    {
        return PlatformDailyMetric::query()
            ->where('date', $date->utc()->toDateString())
            ->where('metric', $metric->value)
            ->pluck('value', 'dimension')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }

    /**
     * Whether this metric has ever been non-zero for a dimension **strictly before** a day.
     *
     * The one question that separates a reactivation from a new customer, and it has to be asked
     * of the whole history rather than of a window: a tenant that churned eighteen months ago and
     * came back today is not new, however far back you have to look to know it.
     */
    public function everPositiveBefore(CarbonImmutable $date, PlatformMetric $metric, string $dimension): bool
    {
        return PlatformDailyMetric::query()
            ->where('date', '<', $date->utc()->toDateString())
            ->where('metric', $metric->value)
            ->where('dimension', $dimension)
            ->where('value', '>', 0)
            ->exists();
    }

    /**
     * The same question for a tenant whose dimension carries more than its id — `t7|team`.
     *
     * A `LIKE` on a prefix that is only `t`, digits and a pipe, so there is deliberately **no
     * `ESCAPE` clause**: the pattern contains no `%`, no `_` and no backslash, and adding an escape
     * character here is how the Signals exporter ended up with a `LIKE ... ESCAPE '\'` that MySQL
     * refused. The id is cast to `int` by the signature, which is what makes that claim true rather
     * than hopeful.
     */
    public function everPositiveBeforeForTeam(CarbonImmutable $date, PlatformMetric $metric, int $teamId): bool
    {
        return PlatformDailyMetric::query()
            ->where('date', '<', $date->utc()->toDateString())
            ->where('metric', $metric->value)
            ->where('dimension', 'like', PlatformMetric::teamDimension($teamId).'|%')
            ->where('value', '>', 0)
            ->exists();
    }

    /**
     * The window's figure for a metric, collapsed the way the metric requires — summed for a flow,
     * taken from the window's last populated day for a snapshot.
     */
    public function total(PlatformMetric $metric, DateRange $range, ?string $dimension = null): int
    {
        return array_sum($this->byDimension($metric, $range, $dimension));
    }

    /**
     * The window's figure broken down by dimension.
     *
     * @return array<string, int> dimension => value, largest first
     */
    public function byDimension(PlatformMetric $metric, DateRange $range, ?string $dimension = null): array
    {
        $query = $this->constrain($this->query($metric), $range);

        if ($dimension !== null) {
            $query->where('dimension', $dimension);
        }

        if ($metric->isSnapshot()) {
            // The last day in the window the rollup **reached**, not the last day this metric
            // happened to have a row on.
            //
            // The difference is the whole reason `PlatformMetric::marker()` exists. Snapshots only
            // write rows for non-zero dimensions, so the night the platform's last paying tenant
            // cancels, MRR writes nothing — and a "last populated day" reader would fall back to
            // the last day that *had* revenue and report it as current for as long as the window
            // contained it. A frozen figure is worse than a zero. Against the marker, an emptied
            // snapshot correctly reads as nothing at all.
            $day = $this->latestKnownDay($metric, $range);

            if ($day === null) {
                return [];
            }

            $query->where('date', $day);
        }

        /** @var array<string, int> $rows */
        $rows = $query
            ->selectRaw('dimension, SUM(value) as aggregate')
            ->groupBy('dimension')
            ->pluck('aggregate', 'dimension')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        arsort($rows);

        return $rows;
    }

    /**
     * The window **summed** by dimension, whatever the metric's own aggregation says.
     *
     * The one deliberate escape from rule 2 in the class note, and it has exactly one caller: the
     * margin report prorates a tenant's monthly revenue across the window by summing its daily MRR
     * snapshots and dividing by a nominal month. That sum is not "revenue over the window" and must
     * never be shown as a figure — it is an intermediate whose only correct use is the division
     * that follows it, which is why the method is named for what it does rather than for what it
     * means.
     *
     * @return array<string, int>
     */
    public function sumByDimension(PlatformMetric $metric, DateRange $range): array
    {
        return $this->constrain($this->query($metric), $range)
            ->selectRaw('dimension, SUM(value) as aggregate')
            ->groupBy('dimension')
            ->pluck('aggregate', 'dimension')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }

    /**
     * Every dimension of a metric that has ever held a value above zero.
     *
     * One query for the whole history, because the retention report needs "did this tenant ever
     * pay us" for every cohort at once and asking it per tenant would be a query per tenant.
     *
     * @return list<string>
     */
    public function dimensionsEverPositive(PlatformMetric $metric): array
    {
        return array_values($this->query($metric)
            ->where('value', '>', 0)
            ->distinct()
            ->pluck('dimension')
            ->map(static fn (mixed $dimension): string => (string) $dimension)
            ->all());
    }

    /**
     * A metric's day-by-day series over the window — the shape a trend chart reads.
     *
     * Only the days that have rows. A chart that needs a continuous axis fills the gaps itself,
     * because only the chart knows whether a missing day is a zero (no signups) or an absence (the
     * rollup had not been written yet), and this class does not.
     *
     * @return array<string, int> `Y-m-d` => value
     */
    public function series(PlatformMetric $metric, DateRange $range, ?string $dimension = null): array
    {
        $query = $this->constrain($this->query($metric), $range);

        if ($dimension !== null) {
            $query->where('dimension', $dimension);
        }

        return $query
            ->selectRaw('date, SUM(value) as aggregate')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('aggregate', 'date')
            ->mapWithKeys(static fn (mixed $value, mixed $day): array => [
                // SQLite hands back the raw `Y-m-d`; MySQL hands back a datetime string. Both are
                // normalised here so a chart's x-axis does not depend on the driver.
                substr((string) $day, 0, 10) => (int) $value,
            ])
            ->all();
    }

    /**
     * The most recent day inside the window that has a row for this metric, or null when none has.
     */
    public function latestDay(PlatformMetric $metric, DateRange $range): ?string
    {
        $day = $this->constrain($this->query($metric), $range)->max('date');

        return $day === null ? null : substr((string) $day, 0, 10);
    }

    /**
     * The most recent day inside the window for which this metric was **computed** — read from its
     * bookkeeping marker rather than from its own rows. See {@see self::byDimension()}.
     */
    public function latestKnownDay(PlatformMetric $metric, DateRange $range): ?string
    {
        $marker = $metric->marker();

        return $this->latestDay($marker ?? $metric, $range);
    }

    /**
     * The most recent day **strictly before** `$date` that has a row for this metric.
     *
     * The movement baseline. Without it, a night the scheduler missed makes the next run compare
     * every paying tenant against nothing and book the entire platform's revenue as new or
     * reactivated — a figure nobody would believe and nobody could correct, because the missing
     * day's flows are gone once the sweeps have pruned the ledgers behind them.
     */
    public function latestDayBefore(CarbonImmutable $date, PlatformMetric $metric): ?string
    {
        $day = $this->query($metric)
            ->where('date', '<', $date->utc()->toDateString())
            ->max('date');

        return $day === null ? null : substr((string) $day, 0, 10);
    }

    /** The last day rolled up at all, for the "figures as at" line every screen carries. */
    public function lastRolledUpDay(): ?string
    {
        $day = PlatformDailyMetric::query()->max('date');

        return $day === null ? null : substr((string) $day, 0, 10);
    }

    /**
     * @return Builder<PlatformDailyMetric>
     */
    private function query(PlatformMetric $metric): Builder
    {
        return PlatformDailyMetric::query()->where('metric', $metric->value);
    }

    /**
     * Narrow to the window's **UTC days**.
     *
     * Not {@see DateRange::constrain()}, and the difference is the whole reason this method has a
     * name. That one binds instants, which is right for `created_at`; `date` holds a day, and the
     * window's exclusive upper instant (`2026-11-01 00:00:00`) compares *greater* than the string
     * `2026-11-01`, so a `<` against it would silently pull in the day after the window.
     *
     * `->utc()` first, for the reason `DateRange` spells out: the bounds are Carbon instances in
     * whatever timezone the window was cut in, and taking `toDateString()` off a Dubai instant
     * would start the window on the wrong day for the four hours either side of midnight. The
     * panel always cuts its windows in UTC, and this is the line that means a window cut anywhere
     * else would still land on the right days.
     *
     * @param  Builder<PlatformDailyMetric>  $query
     * @return Builder<PlatformDailyMetric>
     */
    private function constrain(Builder $query, DateRange $range): Builder
    {
        if ($range->from !== null) {
            $query->where('date', '>=', $range->from->utc()->toDateString());
        }

        if ($range->to !== null) {
            // Inclusive, over the last day the window covers — the same `subSecond()` reading of
            // the exclusive bound that `DateRange::toDate()` gives the screen, so the figures and
            // the subtitle cannot describe different windows.
            $query->where('date', '<=', $range->to->utc()->subSecond()->toDateString());
        }

        return $query;
    }
}
