<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\BusinessIntelligence\Actions\GetLatestReportAction;
use App\Modules\BusinessIntelligence\Http\Resources\BusinessIntelReportResource;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\Core\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Versioned `/api/v1` read of the team's latest background-check report, consumed by the mobile app.
 * Returns an API Resource only (never the model) and reuses the same read Action as the web screen.
 */
final class BusinessIntelController extends Controller
{
    public function show(Request $request, GetLatestReportAction $getLatest): BusinessIntelReportResource|JsonResponse
    {
        $team = $this->team($request);

        $this->authorize('view', $this->transientReport($team));

        $report = $getLatest->handle($team);

        if (! $report instanceof BusinessIntelReport) {
            return new JsonResponse(['data' => null]);
        }

        return BusinessIntelReportResource::make($report);
    }

    private function team(Request $request): Team
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        return $team;
    }

    private function transientReport(Team $team): BusinessIntelReport
    {
        return (new BusinessIntelReport)->forceFill(['team_id' => $team->getKey()]);
    }
}
