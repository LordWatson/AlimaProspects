<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Modules\LeadsWorkspace\Contracts\ExportRowAllowance;
use App\Modules\LeadsWorkspace\Enums\ExportState;
use App\Modules\LeadsWorkspace\Models\LeadExport;
use App\Modules\LeadsWorkspace\Services\LeadCsvExporter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes one export's file and settles what it cost (PL6).
 *
 * The **one** place a file is produced, called both from the request (small exports) and from the
 * job (everything else). Two code paths writing CSV would be two places the escaping, the BOM or
 * the suppression rule could differ, and the one that differed would be the one nobody tested.
 *
 * It is idempotent on the row rather than on a handle: a retried job rewrites the file at a new
 * path and settles the same reservation, and {@see ExportRowAllowance::commit()} is itself
 * idempotent, so the second settlement is a no-op rather than a second charge.
 */
final class GenerateLeadExportAction
{
    public function __construct(
        private readonly LeadCsvExporter $exporter,
        private readonly ExportRowAllowance $allowance,
    ) {}

    public function handle(LeadExport $export): LeadExport
    {
        $team = $export->team;

        $rows = $this->exporter->rows(
            $team,
            $export->toFilters(),
            $export->preset,
            $export->columnKeys(),
        );

        // `php://temp` spills to a temporary file past a few megabytes, so a 50,000-row export
        // costs the same memory as a 50-row one and neither needs a path of our own to clean up.
        $handle = fopen('php://temp/maxmemory:'.(5 * 1024 * 1024), 'w+b');

        if ($handle === false) {
            throw new RuntimeException('Could not open a buffer for the export.');
        }

        $path = $this->path($export);
        $tally = ['rows' => 0, 'suppressed' => 0];

        try {
            // Excel reads a CSV as the system codepage unless the file says otherwise, so a UTF-8
            // BOM is the difference between "Café Ltd" and "CafÃ© Ltd" — and between an Arabic
            // name and a line of question marks — in the one application most teams open this in.
            fwrite($handle, "\u{FEFF}");

            foreach ($rows as $row) {
                // `escape: ''` disables PHP's non-standard escaping, which is deprecated as a
                // default from 8.4 and was never what a CSV reader expects: RFC 4180 doubles a
                // quote inside a quoted field, and the legacy backslash behaviour could corrupt a
                // cell ending in one.
                fputcsv($handle, $row, escape: '');
            }

            /** @var array{rows: int, suppressed: int} $tally */
            $tally = $rows->getReturn();

            rewind($handle);

            Storage::disk($this->disk())->writeStream($path, $handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $export->forceFill([
            'path' => $path,
            'row_count' => $tally['rows'],
            'suppressed_count' => $tally['suppressed'],
            'state' => ExportState::Ready->value,
            'expires_at' => now()->addDays($this->retainDays()),
            'completed_at' => now(),
        ])->save();

        // What the file actually holds, not what was estimated: the unspent hold is released by the
        // same call (`UsageAllowance::commit()`).
        $this->allowance->commit((string) $export->reservation, $tally['rows']);

        return $export;
    }

    /**
     * Give up on an export: release the hold and mark the row.
     *
     * Called from **both** failure paths — the job's `failed()`, and the request, which never
     * reaches it. The release is the part that matters: a hold nobody settles sits against the
     * team's allowance until the month turns over, so a handful of failed exports would lock a
     * team out of exporting for weeks over files they never received.
     *
     * A row that reached `Ready` is left alone. A worker killed after the file was written and the
     * allowance settled still reports a failure, and flipping that row would take away a file the
     * member can actually download.
     */
    public function fail(LeadExport $export): void
    {
        if ($export->state === ExportState::Ready) {
            return;
        }

        $this->allowance->release((string) $export->reservation);

        $export->forceFill(['state' => ExportState::Failed->value])->save();
    }

    /**
     * Where the file lives.
     *
     * Under the team's own id and with a random name: the path is never a route parameter — the
     * download is addressed by the row's id and scoped to the team — but a guessable filename on a
     * disk somebody later makes public is a hole that costs nothing to not have.
     *
     * **Reused once the row has one**, so a retry overwrites its own file rather than writing a
     * second: a file no row points at is a team's whole lead list that the prune will never find.
     */
    private function path(LeadExport $export): string
    {
        return $export->path ?? 'exports/'.$export->team_id.'/'.Str::uuid().'.csv';
    }

    private function disk(): string
    {
        return (string) config('leads-workspace.export.disk', 'local');
    }

    private function retainDays(): int
    {
        return max(1, (int) config('leads-workspace.export.retain_days', 7));
    }
}
