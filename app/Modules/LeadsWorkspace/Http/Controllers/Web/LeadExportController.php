<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\LeadsWorkspace\Exceptions\ExportException;
use App\Modules\LeadsWorkspace\Http\Controllers\Web\Concerns\ResolvesWorkspace;
use App\Modules\LeadsWorkspace\Models\LeadExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands over a finished export file (PL6).
 *
 * **Three independent checks, and the first is the one that matters.** The file is addressed by the
 * row's id and the row is matched against the current team, so one team cannot read another's
 * export by guessing a number — the path on disk is never a parameter at all. Then the same
 * `LeadPolicy::export` that produced it, because the file is the team's whole list and a Read-only
 * member who was sent the link is not entitled to it. Then the row's own expiry, because the file
 * is deleted after `leads-workspace.export.retain_days` and a link that outlives it should say so
 * rather than 500.
 *
 * There is deliberately **no signature on the link**. The plan called for a signed URL good for 24
 * hours; what shipped needs the member's own session *and* the row's expiry, which is strictly
 * narrower — a signed URL is for a door with no login behind it (PL5's verification link is the
 * example), and adding one here would only make the URL in a notification unopenable from a
 * different browser.
 */
final class LeadExportController extends Controller
{
    use ResolvesWorkspace;

    public function download(Request $request, LeadExport $export): StreamedResponse
    {
        $team = $this->team($request);

        abort_unless((int) $export->team_id === (int) $team->getKey(), 404);

        $this->authorize('export', $this->transientLead($team));

        $disk = Storage::disk((string) config('leads-workspace.export.disk', 'local'));

        if (! $export->isDownloadable() || ! $disk->exists((string) $export->path)) {
            throw ExportException::fileGone();
        }

        return $disk->download((string) $export->path, $this->filename($export), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // One team's lead data: no proxy or browser cache should keep a copy.
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * What the file is called on the member's disk — dated, and naming the preset, so three
     * exports taken in one afternoon do not all land as `alima-leads.csv`.
     */
    private function filename(LeadExport $export): string
    {
        return 'alima-leads-'.$export->preset->value.'-'
            .($export->completed_at ?? $export->created_at ?? now())->format('Y-m-d').'.csv';
    }
}
