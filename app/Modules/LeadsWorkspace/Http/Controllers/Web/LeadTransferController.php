<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\LeadsWorkspace\Actions\BulkUpdateLeadsAction;
use App\Modules\LeadsWorkspace\Actions\ExportLeadsAction;
use App\Modules\LeadsWorkspace\Actions\ImportLeadsAction;
use App\Modules\LeadsWorkspace\Actions\StartLeadExportAction;
use App\Modules\LeadsWorkspace\Http\Controllers\Web\Concerns\ResolvesWorkspace;
use App\Modules\LeadsWorkspace\Http\Requests\BulkLeadActionRequest;
use App\Modules\LeadsWorkspace\Http\Requests\ImportLeadsRequest;
use App\Modules\LeadsWorkspace\Http\Requests\ListLeadsRequest;
use App\Modules\LeadsWorkspace\Http\Requests\StartLeadExportRequest;
use App\Modules\LeadsWorkspace\Services\LeadCsvImporter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Leads in and out in bulk (Phase 10b): a bulk action over a selection, a CSV import, a CSV export.
 *
 * Import and export are **Owner/Admin** (`LeadPolicy::import`/`export`), not plain `work`: an
 * import can add thousands of rows and an export takes the team's whole list — contacts included —
 * out of the platform (docs/compliance.md). A bulk action is `work`, because it is the same work a
 * member could do one lead at a time.
 */
final class LeadTransferController extends Controller
{
    use ResolvesWorkspace;

    public function bulk(BulkLeadActionRequest $request, BulkUpdateLeadsAction $bulk): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('work', $this->transientLead($team));

        $changed = $bulk->handle($team, $user, $request->bulkData());

        Inertia::flash('toast', $changed === 0
            ? ['type' => 'info', 'message' => __('Nothing to do — Alima couldn’t find those leads.')]
            : ['type' => 'success', 'message' => __(':count leads updated.', ['count' => $changed])]);

        return back();
    }

    public function import(
        ImportLeadsRequest $request,
        ImportLeadsAction $import,
    ): RedirectResponse {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('import', $this->transientLead($team));

        // The upload's own temp path, read in place: the file is the team's lead data and has no
        // business being copied into storage to be read once and discarded.
        $result = $import->handle($team, $user, $request->csv()->getRealPath(), $request->mapping());

        Inertia::flash('toast', [
            'type' => count($result->skipped) > 0 || $result->truncated ? 'warning' : 'success',
            'message' => $this->importMessage($result->created, $result->merged, count($result->skipped), $result->truncated),
        ]);

        return back();
    }

    /**
     * The export honours whatever the leads list is filtered to — an export is "give me what I'm
     * looking at", not "give me everything".
     */
    public function export(
        ListLeadsRequest $request,
        ExportLeadsAction $export,
    ): StreamedResponse {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('export', $this->transientLead($team));

        return $export->handle($team, $user, $request->filters());
    }

    /**
     * The export as PL6 does it: a preset, a receipt, and a file that may be built by a worker.
     *
     * Behind `prelaunch.export`, with the Phase 10b streamed export above still serving every team
     * the flag is off for. Two paths out of here and both are a browser navigation rather than an
     * Inertia visit, because what the member gets back is a file:
     *
     * - **Small enough to wait for:** a redirect to the download, so the file arrives in the click
     *   they made. The receipt — rows written, companies left out — is on the row, and the leads
     *   screen lists it.
     * - **Too big:** back to the list with a toast, and a notification when the worker has it.
     */
    public function exportFile(
        StartLeadExportRequest $request,
        StartLeadExportAction $start,
    ): RedirectResponse {
        $user = $this->user($request);
        $team = $this->team($request);

        // A route that is not live for this team is a route that does not exist, rather than one
        // that answers 403 — the flag is about whether the code is finished, not about permission.
        abort_unless(PrelaunchFeature::Export->activeFor($team), 404);

        $this->authorize('export', $this->transientLead($team));

        $export = $start->handle($team, $user, $request->filters(), $request->preset(), $request->columns());

        if ($export->isDownloadable()) {
            // No toast: the file landing in their downloads is the feedback, and a flashed message
            // would surface at whatever moment they next navigated.
            return redirect()->route('leads.exports.download', ['export' => $export->getKey()]);
        }

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => __('Alima is building your export. We’ll tell you the moment it’s ready.'),
        ]);

        return back();
    }

    /**
     * Tell the member what actually happened, not just "done": "40 imported, 3 merged, 1 skipped"
     * is the only answer that says whether to go looking for something.
     */
    private function importMessage(int $created, int $merged, int $skipped, bool $truncated): string
    {
        $parts = [__(':count leads imported.', ['count' => $created])];

        if ($merged > 0) {
            $parts[] = __(':count merged into leads you already had.', ['count' => $merged]);
        }

        if ($skipped > 0) {
            $parts[] = __(':count rows skipped — they had no company name.', ['count' => $skipped]);
        }

        if ($truncated) {
            $parts[] = __('The file had more rows than one import reads, so upload the rest separately.');
        }

        return implode(' ', $parts);
    }

    /**
     * The import screen: the member picks a file, the client reads its header row and maps the
     * columns. The headers are read on the client rather than here because a two-step server flow
     * would mean storing the upload between steps — and a file of leads is the last thing to leave
     * lying around.
     *
     * @see LeadCsvImporter for the server-side read that follows.
     */
    public function importForm(ListLeadsRequest $request): Response
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('import', $this->transientLead($team));

        return Inertia::render('leads/Import', [
            'fields' => ImportLeadsAction::FIELDS,
            'maxRows' => (int) config('leads-workspace.csv.max_import_rows', 2_000),
            'maxUploadKb' => (int) config('leads-workspace.csv.max_upload_kb', 5_120),
            'can' => ['import' => $user->can('import', $this->transientLead($team))],
        ]);
    }
}
