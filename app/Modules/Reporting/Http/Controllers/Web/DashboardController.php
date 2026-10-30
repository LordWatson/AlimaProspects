<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Reporting\Http\Requests\DashboardRequest;
use App\Modules\Reporting\Services\DashboardReportBuilder;
use App\Modules\Reporting\Support\ReportFilterOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dashboard (Inertia). Thin: authorise against the team, call one Service, render.
 *
 * Server-driven filtering, like the leads list: the window is read from the query string and every
 * figure is aggregated against it, so a shared URL reproduces exactly what the sender saw and the
 * export link carries the same window.
 */
final class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, DashboardReportBuilder $builder): Response
    {
        $team = $this->team($request);

        $this->authorize('viewReports', $team);

        $filters = $request->filters();

        return Inertia::render('Dashboard', [
            'report' => $builder->forTeam($team, $filters),
            'filters' => $filters->toFormState((string) $team->timezone),
            'options' => ReportFilterOptions::all(),
        ]);
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
