<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use App\Modules\Reporting\Enums\TrendGranularity;
use App\Modules\Reporting\Services\DashboardReportBuilder;
use Spatie\LaravelData\Data;

/**
 * Everything the dashboard renders, in one shape, built by
 * {@see DashboardReportBuilder}.
 *
 * Two flags the screen branches on, and they are **not** the same question. `has_ever_sourced`
 * decides whether the team sees the "how Alima works" explainer instead of a wall of zeroed
 * charts; `has_data` decides whether *this window* has anything to draw. Deciding both here rather
 * than in the template keeps the rule in one place for the mobile app (Phase 18) to reuse.
 *
 * Every figure in here is scoped to `filters` — one window for the whole screen, so the charts
 * cannot disagree with each other (see `DateRange` for what the window selects). The resolved
 * bounds travel alongside the filters so the screen can say which dates it is showing without
 * re-deriving them from a preset name, and an export link can carry exactly the same window.
 */
final class DashboardReport extends Data
{
    /**
     * @param  list<Kpi>  $kpis
     * @param  list<FunnelStep>  $funnel
     * @param  list<TrendPoint>  $trend
     * @param  list<BreakdownSlice>  $grades  A–D fit mix, always all four letters.
     * @param  list<BreakdownSlice>  $stages  Pipeline stages in board order.
     * @param  list<BreakdownSlice>  $sources  Lead sources, busiest first.
     * @param  list<BreakdownSlice>  $signal_types  Signal event types, busiest first.
     * @param  list<ReportTable>  $tables  The tabular reports, in tab order.
     */
    public function __construct(
        public readonly array $kpis,
        public readonly array $funnel,
        public readonly array $trend,
        public readonly array $grades,
        public readonly array $stages,
        public readonly array $sources,
        public readonly array $signal_types,
        public readonly array $tables,
        public readonly ReportFilters $filters,
        /** The window's first day as an ISO date in the team's timezone; null for all time. */
        public readonly ?string $range_from,
        /** The window's last day (inclusive) as an ISO date; null for all time. */
        public readonly ?string $range_to,
        /** How wide one bar of the trend is, so the chart can title itself honestly. */
        public readonly TrendGranularity $trend_granularity,
        /** Buckets in `trend` — the chart's own subtitle counts these, not a hard-coded 12. */
        public readonly int $trend_buckets,
        /** Whether the **selected window** has anything in it. */
        public readonly bool $has_data,
        /**
         * Whether the team has **ever** sourced a lead, which is the flag the first-run explainer
         * turns on. Kept apart from `has_data` because a team with a thousand leads that filters
         * to a quiet week has an empty window, not an empty account, and showing it "here's how
         * Alima works" would be telling it it had never started.
         */
        public readonly bool $has_ever_sourced,
    ) {}
}
