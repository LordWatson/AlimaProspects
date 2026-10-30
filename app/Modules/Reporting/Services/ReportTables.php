<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Data\BreakdownSlice;
use App\Modules\Reporting\Data\DashboardReport;
use App\Modules\Reporting\Data\FunnelStep;
use App\Modules\Reporting\Data\Kpi;
use App\Modules\Reporting\Data\ReportColumn;
use App\Modules\Reporting\Data\ReportRow;
use App\Modules\Reporting\Data\ReportTable;
use App\Modules\Reporting\Data\TrendPoint;
use App\Modules\Reporting\Enums\ColumnFormat;
use App\Modules\Reporting\Enums\KpiFormat;
use App\Modules\Reporting\Enums\ReportKey;

/**
 * Turns any report on the dashboard into a {@see ReportTable}.
 *
 * This is what makes "CSV export of **any** report" one code path. Four of the reports are tables
 * already and come straight off the built {@see DashboardReport}; the rest are charts, and this
 * class is the one place that says what a chart looks like as rows — so the download and the chart
 * are the same numbers by construction, not by two implementations agreeing.
 *
 * A **pure projection**: no queries, no formatting, no locale. It reads a report that has already
 * been built and rearranges it.
 */
final class ReportTables
{
    public function one(DashboardReport $report, ReportKey $key): ReportTable
    {
        foreach ($report->tables as $table) {
            if ($table->key === $key) {
                return $table;
            }
        }

        return match ($key) {
            ReportKey::Kpis => $this->kpis($report),
            ReportKey::Funnel => $this->funnel($report),
            ReportKey::Trend => $this->trend($report),
            ReportKey::Grades => $this->breakdown($key, $report->grades, 'Grade'),
            ReportKey::Stages => $this->breakdown($key, $report->stages, 'Stage'),
            ReportKey::Sources => $this->breakdown($key, $report->sources, 'Source'),
            ReportKey::SignalTypes => $this->breakdown($key, $report->signal_types, 'Event type'),
            // The tabular reports are returned by the loop above. Reaching here would mean the
            // builder stopped producing one of them, which is a bug, not an empty download.
            ReportKey::IcpPerformance, ReportKey::Outreach,
            ReportKey::TeamActivity, ReportKey::AiUsage => ReportTable::of($key, [], []),
        };
    }

    private function kpis(DashboardReport $report): ReportTable
    {
        $currency = null;

        foreach ($report->kpis as $kpi) {
            if ($kpi->format === KpiFormat::Money && $kpi->currency !== null) {
                $currency = $kpi->currency;
            }
        }

        return ReportTable::of(
            ReportKey::Kpis,
            [
                ReportColumn::translated('metric', 'Figure'),
                // One column for every tile, so a money tile and a count tile can share it: the
                // *row* knows which it is, and the CSV writes the number either way. Splitting
                // money into its own column would leave most cells empty.
                ReportColumn::count('value', 'Value'),
                ReportColumn::count('delta', 'Change'),
                ReportColumn::translated('delta_label', 'Compared with'),
                $currency === null
                    ? ReportColumn::count('money', 'Amount')
                    : ReportColumn::money('money', 'Amount', $currency),
            ],
            array_map(
                static fn (Kpi $kpi): ReportRow => new ReportRow(
                    key: $kpi->key,
                    cells: [
                        'metric' => $kpi->label,
                        'value' => $kpi->format === KpiFormat::Money ? null : $kpi->value,
                        'delta' => $kpi->delta,
                        'delta_label' => $kpi->delta_label,
                        'money' => $kpi->format === KpiFormat::Money ? $kpi->value : null,
                    ],
                ),
                $report->kpis,
            ),
        );
    }

    private function funnel(DashboardReport $report): ReportTable
    {
        $top = null;

        foreach ($report->funnel as $step) {
            if ($step->value !== null && $top === null) {
                $top = $step->value;
            }
        }

        return ReportTable::of(
            ReportKey::Funnel,
            [
                ReportColumn::translated('step', 'Step'),
                ReportColumn::count('value', 'Prospects'),
                ReportColumn::percent('share', 'Share of sourced'),
            ],
            array_map(
                static fn (FunnelStep $step): ReportRow => new ReportRow(
                    key: $step->key,
                    cells: [
                        'step' => $step->label,
                        // Null, not zero, when the step has no source of truth — the same claim
                        // the screen's em dash makes (see {@see FunnelStep}).
                        'value' => $step->available ? $step->value : null,
                        'share' => $step->available && $step->value !== null
                            && $top !== null && $top > 0
                            ? round($step->value / $top, 4)
                            : null,
                    ],
                ),
                $report->funnel,
            ),
        );
    }

    private function trend(DashboardReport $report): ReportTable
    {
        return ReportTable::of(
            ReportKey::Trend,
            [
                // The bucket's own start date, not a formatted axis label: a spreadsheet can sort
                // and chart a date, and cannot do either with "6 Oct".
                new ReportColumn('starts_on', 'Period starting', ColumnFormat::Date),
                ReportColumn::count('sourced', 'Sourced'),
                ReportColumn::count('qualified', 'Strong fits'),
                ReportColumn::percent('rate', 'Strong-fit rate'),
            ],
            array_map(
                static fn (TrendPoint $point): ReportRow => new ReportRow(
                    key: $point->starts_on,
                    cells: [
                        'starts_on' => $point->starts_on,
                        'sourced' => $point->sourced,
                        'qualified' => $point->qualified,
                        'rate' => $point->sourced > 0
                            ? round($point->qualified / $point->sourced, 4)
                            : null,
                    ],
                ),
                $report->trend,
            ),
        );
    }

    /**
     * Every one-dimensional breakdown shares a shape, so they share a projection — the label
     * column is all that differs.
     *
     * @param  list<BreakdownSlice>  $slices
     */
    private function breakdown(ReportKey $key, array $slices, string $labelColumn): ReportTable
    {
        $total = 0;

        foreach ($slices as $slice) {
            $total += $slice->value;
        }

        return ReportTable::of(
            $key,
            [
                ReportColumn::translated('label', $labelColumn),
                ReportColumn::count('value', 'Prospects'),
                ReportColumn::percent('share', 'Share'),
                ReportColumn::translated('hint', 'Notes'),
            ],
            array_map(
                static fn (BreakdownSlice $slice): ReportRow => new ReportRow(
                    key: $slice->key,
                    cells: [
                        'label' => $slice->label,
                        'value' => $slice->value,
                        'share' => $total > 0 ? round($slice->value / $total, 4) : null,
                        'hint' => $slice->hint,
                    ],
                ),
                $slices,
            ),
        );
    }
}
