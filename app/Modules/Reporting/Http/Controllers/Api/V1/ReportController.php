<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Reporting\Http\Requests\DashboardRequest;
use App\Modules\Reporting\Http\Resources\DashboardReportResource;
use App\Modules\Reporting\Services\DashboardReportBuilder;
use Illuminate\Http\Request;

/**
 * The dashboard over `/api/v1` — the endpoint the mobile app's home screen reads (Phase 18), and
 * the `/api/v1/reports` that {@see DashboardRequest} and {@see DashboardReportBuilder} were both
 * written in anticipation of.
 *
 * Thin by the same rule as the web controller: authorise against the team, call the one Service,
 * return a Resource. It shares the FormRequest with the web screen, so `range`/`from`/`to` cannot
 * come to mean something different on a phone — which is the whole risk this phase is guarding
 * against.
 *
 * It returns the **whole** report, tables included, rather than a lighter mobile-only subset. One
 * window, one set of figures: a second, slimmer dashboard shape would be a second place for the
 * cohort rules to drift, and `DashboardReportBuilder` already builds every aggregate in one pass
 * regardless of who asked.
 */
final class ReportController extends Controller
{
    public function dashboard(DashboardRequest $request, DashboardReportBuilder $builder): DashboardReportResource
    {
        $team = $this->team($request);

        $this->authorize('viewReports', $team);

        return DashboardReportResource::make($builder->forTeam($team, $request->filters()));
    }

    private function team(Request $request): Team
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        return $team;
    }
}
