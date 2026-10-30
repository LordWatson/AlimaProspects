<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Reporting\Actions\BuildAiUsageAction;
use App\Modules\Reporting\Actions\BuildGradeMixAction;
use App\Modules\Reporting\Actions\BuildHeadlineKpisAction;
use App\Modules\Reporting\Actions\BuildIcpPerformanceAction;
use App\Modules\Reporting\Actions\BuildLeadFunnelAction;
use App\Modules\Reporting\Actions\BuildLeadTrendAction;
use App\Modules\Reporting\Actions\BuildOutreachPerformanceAction;
use App\Modules\Reporting\Actions\BuildPipelineBreakdownAction;
use App\Modules\Reporting\Actions\BuildSignalBreakdownAction;
use App\Modules\Reporting\Actions\BuildSourceBreakdownAction;
use App\Modules\Reporting\Actions\BuildTeamActivityAction;
use App\Modules\Reporting\Data\DashboardReport;
use App\Modules\Reporting\Data\FunnelStep;
use App\Modules\Reporting\Data\ReportFilters;
use App\Modules\Reporting\Data\ReportTable;
use App\Modules\Reporting\Enums\ReportKey;
use App\Modules\Reporting\Support\DateRange;
use LogicException;

/**
 * Assembles the dashboard read-model from the focused aggregation Actions (CLAUDE.md §2: Services
 * orchestrate, Actions do one thing). Nothing here touches the database directly — which is what
 * lets the mobile dashboard (Phase 18) and a future `/api/v1/reports` reuse the same numbers
 * without the web controller.
 *
 * It builds the **whole** report even when a caller only wants one table out of it (the CSV
 * export does). That is deliberate: an export that re-queried just its own slice could disagree
 * with the screen it was launched from, and a download nobody can reconcile with the dashboard is
 * worse than no download. At MVP volumes the cost is a couple of dozen indexed aggregates.
 *
 * Live aggregation, no rollup tables. `report_rollups` stays the escape hatch if volumes make
 * these queries slow; nothing above this class would have to change to add it.
 */
final readonly class DashboardReportBuilder
{
    public function __construct(
        private BuildHeadlineKpisAction $kpis,
        private BuildLeadFunnelAction $funnel,
        private BuildLeadTrendAction $trend,
        private BuildGradeMixAction $grades,
        private BuildPipelineBreakdownAction $stages,
        private BuildSourceBreakdownAction $sources,
        private BuildSignalBreakdownAction $signals,
        private BuildIcpPerformanceAction $icpPerformance,
        private BuildOutreachPerformanceAction $outreach,
        private BuildTeamActivityAction $activity,
        private BuildAiUsageAction $aiUsage,
        private TeamContext $teamContext,
    ) {}

    public function forTeam(Team $team, ?ReportFilters $filters = null): DashboardReport
    {
        $filters ??= new ReportFilters;
        $range = $filters->resolve((string) $team->timezone);

        // Built first: the KPI sparklines are read off it rather than re-queried.
        $trend = $this->trend->handle($team, $range, $this->fallbackPeriods());
        $funnel = $this->funnel->handle($team, $range);

        return new DashboardReport(
            kpis: $this->kpis->handle($team, $range, $trend),
            funnel: $funnel,
            trend: $trend,
            grades: $this->grades->handle($team, $range),
            stages: $this->stages->handle($team, $range),
            sources: $this->sources->handle($team, $range),
            signal_types: $this->signals->handle($team, $range),
            tables: $this->tables($team, $range),
            filters: $filters,
            range_from: $range->fromDate(),
            range_to: $range->toDate(),
            trend_granularity: $range->granularity(),
            trend_buckets: count($trend),
            has_data: $hasData = $this->hasData($funnel),
            has_ever_sourced: $hasData || $this->hasEverSourced($team, $range),
        );
    }

    /**
     * The tabular reports, in the order {@see ReportKey::tabular()} lists them so the screen's tabs
     * and the export menu cannot fall out of step.
     *
     * @return list<ReportTable>
     */
    private function tables(Team $team, DateRange $range): array
    {
        return array_map(
            fn (ReportKey $key): ReportTable => match ($key) {
                ReportKey::IcpPerformance => $this->icpPerformance->handle($team, $range),
                ReportKey::Outreach => $this->outreach->handle($team, $range),
                ReportKey::TeamActivity => $this->activity->handle($team, $range),
                ReportKey::AiUsage => $this->aiUsage->handle($team, $range),
                // Unreachable: `tabular()` returns the four above. The arm exists so that adding a
                // fifth is a compile-time decision rather than a missing tab somebody notices in
                // production.
                default => throw new LogicException("{$key->value} is not a tabular report."),
            },
            ReportKey::tabular(),
        );
    }

    /**
     * How many buckets the trend falls back to when the window is open-ended. A quarter of weeks:
     * long enough to see a direction.
     */
    private function fallbackPeriods(): int
    {
        return max(1, (int) config('reporting.trend_periods', 12));
    }

    /**
     * Whether the **selected window** has anything in it. Keyed off the top of the funnel rather
     * than any one chart, because a window with no leads has nothing in any of them.
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

    /**
     * Whether the team has **ever** sourced a lead, which is a different question from whether this
     * window has one — and the one the first-run explainer turns on.
     *
     * Conflating the two is a real trap: a team with a thousand leads that filters to a quiet week
     * would be shown "here's how Alima works", as though it had never started. An empty *window*
     * needs a nudge to widen the filter; an empty *account* needs the next step.
     *
     * Only asked when the window is narrowing something and came back empty — an unbounded window
     * that found nothing has already answered it, so the usual dashboard view costs no extra query.
     */
    private function hasEverSourced(Team $team, DateRange $range): bool
    {
        if (! $range->isBounded()) {
            return false;
        }

        return $this->teamContext->run($team, fn (): bool => Lead::query()
            ->where('leads.team_id', $team->getKey())
            ->exists());
    }
}
