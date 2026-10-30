<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadsWorkspace\Data\LeadFilters;
use App\Modules\LeadsWorkspace\Services\LeadCsvExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the team's leads — as currently filtered — out as a CSV download (Phase 10b).
 *
 * **Streamed, not built.** The rows are written to the output buffer in chunks as they are read, so
 * a team with tens of thousands of leads doesn't need the whole file in memory or a request long
 * enough to time out before the first byte.
 *
 * The export is audited (who, how many filters, when) but the file's contents are not logged: it is
 * the team's own lead data, and the audit trail has no business holding a second copy.
 */
final class ExportLeadsAction
{
    public function __construct(
        private readonly LeadCsvExporter $exporter,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Team $team, User $actor, LeadFilters $filters): StreamedResponse
    {
        $this->audit->log('leads.exported', null, [
            'filtered' => $filters->isFiltering(),
            // The filter *keys*, so the trail says what kind of export this was without copying
            // the team's search terms into it.
            'filters' => array_keys($filters->toParams()),
        ], $actor);

        $rows = $this->exporter->rows($team, $filters);
        $filename = 'alima-leads-'.now()->format('Y-m-d').'.csv';

        return new StreamedResponse(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // Excel reads a CSV as the system codepage unless the file says otherwise, so a UTF-8
            // BOM is the difference between "Café Ltd" and "CafÃ© Ltd" in the one application most
            // teams will open this in.
            fwrite($handle, "\u{FEFF}");

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            // The response is generated per request and must not be cached by a proxy — it is one
            // team's lead data.
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
