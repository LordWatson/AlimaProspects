<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use App\Modules\Reporting\Data\BreakdownSlice;
use App\Modules\Reporting\Data\FunnelStep;
use App\Modules\Reporting\Data\Kpi;
use Spatie\LaravelData\Data;

/**
 * Everything the platform reporting screen renders (Phase 19c).
 *
 * The chart layer reuses Reporting's own DTOs — {@see Kpi}, {@see FunnelStep},
 * {@see BreakdownSlice} — so the panel draws with the same validated chart components the tenant
 * dashboard does and no second chart set exists to drift from the first. Everything with five or
 * six measures per row is a {@see PlatformReportTable} instead, for the reason `dataviz` gives: a
 * chart of eight series is a chart nobody reads.
 *
 * `last_rolled_up` is on the page rather than buried in a table because it is the first thing an
 * operator needs to know about every figure on it. **Figures are only as fresh as the rollup**, and
 * a nightly command that failed must not look like a quiet week.
 */
final class PlatformReportPage extends Data
{
    /**
     * @param  list<Kpi>  $kpis
     * @param  list<FunnelStep>  $funnel
     * @param  list<BreakdownSlice>  $signups_by_market
     * @param  list<BreakdownSlice>  $conversion_days
     * @param  list<PlatformReportTable>  $tables
     * @param  array<string, int>  $fx_rates  ISO code => minor units of the reporting currency per major unit
     */
    public function __construct(
        public readonly array $kpis,
        public readonly array $funnel,
        public readonly array $signups_by_market,
        public readonly array $conversion_days,
        public readonly array $tables,
        public readonly string $currency,
        public readonly array $fx_rates,
        /** The last day any metric was rolled up, ISO; null when the command has never run. */
        public readonly ?string $last_rolled_up,
        /** The window's first day, ISO (UTC); null for all time. */
        public readonly ?string $range_from,
        /** The window's last day, inclusive, ISO (UTC); null for all time. */
        public readonly ?string $range_to,
        /** The `ReportRange` preset the window came from. */
        public readonly string $range,
        /** False when the window has no rolled-up rows at all — a different message from zero. */
        public readonly bool $has_data,
        /**
         * True when any margin row has cost in a currency with no configured rate, which makes
         * every margin on the page a ceiling rather than a figure.
         */
        public readonly bool $has_unconverted_cost,
    ) {}
}
