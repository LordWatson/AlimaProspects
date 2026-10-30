<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Reporting\Actions\ExportReportAction;
use App\Modules\Reporting\Http\Requests\ExportReportRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads one report as CSV (Phase 13). Thin: authorise, validate, call one Action, stream.
 *
 * Authorised with the **same** `viewReports` policy as the screen, Read-only members included. An
 * export is the report in a different window; gating the file behind a stricter role than the
 * chart would mean the person asked to read the numbers could not take them to a meeting.
 */
final class ReportExportController extends Controller
{
    public function __invoke(
        ExportReportRequest $request,
        ExportReportAction $export,
    ): StreamedResponse {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        $this->authorize('viewReports', $team);

        return $export->handle($team, $user, $request->report(), $request->filters());
    }
}
