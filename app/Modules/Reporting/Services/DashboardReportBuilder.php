<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Reporting\Actions\BuildGradeMixAction;
use App\Modules\Reporting\Actions\BuildHeadlineKpisAction;
use App\Modules\Reporting\Actions\BuildLeadFunnelAction;
use App\Modules\Reporting\Actions\BuildLeadTrendAction;
use App\Modules\Reporting\Actions\BuildPipelineBreakdownAction;
use App\Modules\Reporting\Actions\BuildSignalBreakdownAction;
use App\Modules\Reporting\Actions\BuildSourceBreakdownAction;
use App\Modules\Reporting\Data\DashboardReport;
use App\Modules\Reporting\Data\FunnelStep;

/**
 * Assembles the dashboard read-model from the focused aggregation Actions (CLAUDE.md §2: Services
 * orchestrate, Actions do one thing). Nothing here touches the database directly — which is what
 * lets the mobile dashboard (Phase 18) and a future `/api/v1/reports` reuse the same numbers
 * without the web controller.
 *
 * The date-range filters, CSV export and rollup tables that Phase 13 adds belong here too; this is
 * the "now" of that module, not its finished shape.
 */
final readonly class DashboardReportBuilder
{
    /** How much history the trend chart shows. A quarter: long enough to see a direction. */
    public const TREND_WEEKS = 12;

    public function __construct(
        private BuildHeadlineKpisAction $kpis,
        private BuildLeadFunnelAction $funnel,
        private BuildLeadTrendAction $trend,
        private BuildGradeMixAction $grades,
        private BuildPipelineBreakdownAction $stages,
        private BuildSourceBreakdownAction $sources,
        private BuildSignalBreakdownAction $signals,
    ) {}

    public function forTeam(Team $team): DashboardReport
    {
        // Built first: the KPI sparklines are read off it rather than re-queried.
        $trend = $this->trend->handle($team, self::TREND_WEEKS);
        $funnel = $this->funnel->handle($team);

        return new DashboardReport(
            kpis: $this->kpis->handle($team, $trend),
            funnel: $funnel,
            trend: $trend,
            grades: $this->grades->handle($team),
            stages: $this->stages->handle($team),
            sources: $this->sources->handle($team),
            signal_types: $this->signals->handle($team),
            has_data: $this->hasData($funnel),
            trend_weeks: self::TREND_WEEKS,
        );
    }

    /**
     * Whether there is anything to report on yet. Keyed off the top of the funnel rather than any
     * chart, because a team with no leads has nothing in any of them — and should be shown how
     * Alima works instead of five empty frames.
     *
     * @param  list<FunnelStep>  $funnel
     */
    private function hasData(array $funnel): bool
    {
        foreach ($funnel as $step) {
            if (($step->value ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
