<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Modules\Admin\Enums\PlatformReport;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\Platform\PlatformReportBuilder;
use App\Modules\Reporting\Services\ReportCsvExporter;
use App\Modules\Reporting\Support\DateRange;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one platform report out as CSV (Phase 19c).
 *
 * **The same writer the tenant exports use.** {@see ReportCsvExporter} handles the cells —
 * bare numbers so a spreadsheet can sum them, the unit in the header, an empty cell rather than a
 * zero where a figure is unavailable, and formula neutralisation only where a cell holds words. A
 * second exporter for the panel would have drifted from that, and the thing that would have drifted
 * is the hardening: a tenant's company name reaches these files too, through the margin report's
 * tenant column.
 *
 * The table is **built, then written** — the same {@see PlatformReportBuilder} call the screen
 * makes — so a download and the tab it came from cannot disagree.
 *
 * The export is audited (who, which report, which window, how many rows) and its **contents are
 * not**. These are the business's own numbers rather than a tenant's, so the privacy argument is
 * weaker than Reporting's — but the audit trail still has no business holding a second copy of
 * every tenant's margin.
 */
final class ExportPlatformReportAction
{
    public function __construct(
        private readonly PlatformReportBuilder $builder,
        private readonly ReportCsvExporter $exporter,
        private readonly AdminAudit $audit,
    ) {}

    public function handle(PlatformReport $report, DateRange $range): StreamedResponse
    {
        $table = $this->builder->one($range, $report);

        $this->audit->record(AdminAudit::REPORT_EXPORTED, null, [
            'report' => $report->value,
            'range' => $range->preset->value,
            'from' => $range->fromDate(),
            'to' => $range->toDate(),
            'rows' => count($table->rows),
        ]);

        $rows = $this->exporter->rowsFor($table->columns, $table->rows);
        $filename = $this->exporter->filenameFor($report->slug(), $range->fromDate(), $range->toDate());

        return new StreamedResponse(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // Excel reads a CSV as the system codepage unless the file says otherwise, so the BOM
            // is the difference between "Café Ltd" and "CafÃ© Ltd" — tenant names reach these
            // files through the margin report.
            fwrite($handle, "\u{FEFF}");

            foreach ($rows as $row) {
                // `escape: ''` disables PHP's non-standard escaping, deprecated as a default from
                // 8.4 and never what a CSV reader expects: RFC 4180 doubles a quote inside a quoted
                // field, and the legacy backslash behaviour could corrupt a cell ending in one.
                fputcsv($handle, $row, escape: '');
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
