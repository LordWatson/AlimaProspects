<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Services;

use App\Modules\AiInsights\Data\ActivitySnapshot;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Enums\LeadTaskStatus;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\Reporting\Data\BreakdownSlice;
use App\Modules\Reporting\Data\DashboardReport;
use App\Modules\Reporting\Data\FunnelStep;
use App\Modules\Reporting\Data\Kpi;
use App\Modules\Reporting\Data\ReportFilters;
use App\Modules\Reporting\Data\ReportRow;
use App\Modules\Reporting\Data\ReportTable;
use App\Modules\Reporting\Enums\ReportKey;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Services\DashboardReportBuilder;

/**
 * Turns a week of a team's work into the figures the AI digest reasons about (Phase 14).
 *
 * **It reads Reporting rather than the tables.** Insights interpret; Reporting is the source of
 * the facts (docs/modules/reporting-analytics.md). Going to `leads` and `contact_attempts` directly
 * would be a second definition of "contacted" and a second cohort rule, and the first week the two
 * disagreed the insight would be arguing with the dashboard it sits next to. The price is a couple
 * of dozen indexed aggregates per team per day, off-peak, in a queued job.
 *
 * The window is a fixed **last 7 days**, resolved in the team's timezone by Reporting's own
 * `DateRange`. Fixed rather than configurable because the digest's wording ("this week") is
 * written into the prompt, and a knob that silently changed what "this week" meant would make the
 * model's sentences wrong rather than different.
 */
final readonly class ActivitySnapshotBuilder
{
    public function __construct(
        private DashboardReportBuilder $reports,
        private TeamContext $teamContext,
    ) {}

    public function forTeam(Team $team): ActivitySnapshot
    {
        $report = $this->reports->forTeam($team, new ReportFilters(ReportRange::Last7Days));

        return new ActivitySnapshot(
            periodStart: $report->range_from,
            periodEnd: $report->range_to,
            metrics: [
                ...$this->kpis($report),
                ...$this->funnel($report),
                ...$this->grades($report),
                'open_tasks_overdue' => $this->overdueTasks($team),
            ],
            channels: $this->channels($report),
        );
    }

    /**
     * The headline numbers, each with its change against the previous seven days — which is most of
     * what makes a digest worth reading: a figure without a direction is a fact, not an insight.
     *
     * @return array<string, int|float>
     */
    private function kpis(DashboardReport $report): array
    {
        $metrics = [];

        foreach ($report->kpis as $kpi) {
            /** @var Kpi $kpi */
            $metrics[$kpi->key] = $kpi->value;

            if ($kpi->delta !== null) {
                $metrics[$kpi->key.'_change'] = $kpi->delta;
            }
        }

        return $metrics;
    }

    /**
     * @return array<string, int|float>
     */
    private function funnel(DashboardReport $report): array
    {
        $metrics = [];

        foreach ($report->funnel as $step) {
            /** @var FunnelStep $step */
            // An unavailable step has no number, and sending a zero for it would have the model
            // explaining a drop-off that nobody measured (see `FunnelStep::pending()`).
            if ($step->available && $step->value !== null) {
                $metrics[$step->key] = $step->value;
            }
        }

        return $metrics;
    }

    /**
     * @return array<string, int|float>
     */
    private function grades(DashboardReport $report): array
    {
        $metrics = [];

        foreach ($report->grades as $slice) {
            /** @var BreakdownSlice $slice */
            // `key` is the stored grade letter; `label` is a translation key and has no business
            // in a prompt.
            $metrics['grade_'.strtolower($slice->key)] = $slice->value;
        }

        return $metrics;
    }

    /**
     * Per-channel outreach performance, keyed by the channel's stored value.
     *
     * @return array<string, array<string, int|float>>
     */
    private function channels(DashboardReport $report): array
    {
        $table = $this->table($report, ReportKey::Outreach);

        if ($table === null) {
            return [];
        }

        $channels = [];

        foreach ($table->rows as $row) {
            /** @var ReportRow $row */
            $channels[$row->key] = [
                'attempts' => $this->number($row, 'attempts'),
                'companies' => $this->number($row, 'companies'),
                'replies' => $this->number($row, 'responses'),
                'reply_rate_percent' => $this->number($row, 'reply_rate'),
                'opt_outs' => $this->number($row, 'opt_outs'),
            ];
        }

        return $channels;
    }

    /**
     * Not windowed, deliberately: "three of your tasks are overdue" is about now, and a digest that
     * only counted the ones that came due this week would miss the ones that have been waiting
     * longest. The same choice the notification digest makes (see `DigestBuilder`).
     */
    private function overdueTasks(Team $team): int
    {
        return $this->teamContext->run($team, fn (): int => LeadTask::query()
            ->where('team_id', $team->getKey())
            ->where('status', LeadTaskStatus::Open->value)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->count());
    }

    private function table(DashboardReport $report, ReportKey $key): ?ReportTable
    {
        foreach ($report->tables as $table) {
            /** @var ReportTable $table */
            if ($table->key === $key) {
                return $table;
            }
        }

        return null;
    }

    private function number(ReportRow $row, string $cell): int|float
    {
        $value = $row->cells[$cell] ?? 0;

        return is_numeric($value) ? $value + 0 : 0;
    }
}
