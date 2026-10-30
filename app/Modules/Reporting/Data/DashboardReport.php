<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use App\Modules\Reporting\Services\DashboardReportBuilder;
use Spatie\LaravelData\Data;

/**
 * Everything the dashboard renders, in one shape, built by
 * {@see DashboardReportBuilder}.
 *
 * `has_data` is the single flag the screen branches on: a team that has not sourced a lead yet
 * gets the "how Alima works" explainer instead of a wall of zeroed charts. Deciding that here
 * rather than in the template keeps the rule in one place for the mobile app (Phase 18) to reuse.
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
     */
    public function __construct(
        public readonly array $kpis,
        public readonly array $funnel,
        public readonly array $trend,
        public readonly array $grades,
        public readonly array $stages,
        public readonly array $sources,
        public readonly array $signal_types,
        public readonly bool $has_data,
        /** Weeks covered by `trend`, so the chart can title itself without hard-coding 12. */
        public readonly int $trend_weeks,
    ) {}
}
