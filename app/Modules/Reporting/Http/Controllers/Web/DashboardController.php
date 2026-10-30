<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Reporting\Services\DashboardReportBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dashboard (Inertia). Thin: authorise against the team, call one Service, render.
 *
 * It replaces the `Route::inertia('dashboard')` stub the app shell shipped with in Phase 2 — the
 * screen now reports on real, team-scoped data rather than hard-coded zeroes.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardReportBuilder $builder): Response
    {
        $team = $this->team($request);

        $this->authorize('viewReports', $team);

        return Inertia::render('Dashboard', [
            'report' => $builder->forTeam($team),
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
