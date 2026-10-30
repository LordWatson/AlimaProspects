<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Reporting\Data\ReportFilters;
use App\Modules\Reporting\Enums\ReportKey;
use App\Modules\Reporting\Services\DashboardReportBuilder;
use App\Modules\Reporting\Services\ReportCsvExporter;
use App\Modules\Reporting\Services\ReportTables;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one report out as a CSV download (Phase 13).
 *
 * The report is **built, then projected** — the same {@see DashboardReportBuilder} call the screen
 * makes, through the same {@see ReportTables} projection — so a download and the chart it came from
 * cannot disagree. Re-querying only the asked-for slice would be cheaper and would eventually be
 * wrong.
 *
 * Streamed rather than assembled, matching the leads export: a report is small today, and the
 * shape means it stays fine when `report_rollups` and a longer history make it less so.
 *
 * The export is audited (who, which report, which window) and its **contents are not**: it is the
 * team's own data, and the audit trail has no business holding a second copy.
 */
final class ExportReportAction
{
    public function __construct(
        private readonly DashboardReportBuilder $builder,
        private readonly ReportTables $tables,
        private readonly ReportCsvExporter $exporter,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(
        Team $team,
        User $actor,
        ReportKey $key,
        ReportFilters $filters,
    ): StreamedResponse {
        $report = $this->builder->forTeam($team, $filters);
        $table = $this->tables->one($report, $key);

        $this->audit->log('reports.exported', null, [
            'report' => $key->value,
            'range' => $filters->range->value,
            'from' => $report->range_from,
            'to' => $report->range_to,
            'rows' => count($table->rows),
        ], $actor);

        $rows = $this->exporter->rows($table);
        $filename = $this->exporter->filename($table, $report->range_from, $report->range_to);

        return new StreamedResponse(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // Excel reads a CSV as the system codepage unless the file says otherwise, so a UTF-8
            // BOM is the difference between a readable Arabic report and a screen of mojibake —
            // and between "Café Ltd" and "CafÃ© Ltd" in English.
            fwrite($handle, "\u{FEFF}");

            foreach ($rows as $row) {
                // `escape: ''` disables PHP's non-standard escaping, which is deprecated as a
                // default from 8.4 and was never what a CSV reader expects: RFC 4180 doubles a
                // quote inside a quoted field, and the legacy backslash behaviour could corrupt a
                // cell ending in one.
                fputcsv($handle, $row, escape: '');
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            // Generated per request and must not be cached by a proxy — it is one team's numbers.
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
