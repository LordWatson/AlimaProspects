<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Http\Requests\PaginatedIndexRequest;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Actions\FindDiscoveryRunAction;
use App\Modules\LeadSourcing\Actions\GetDiscoveryRunsAction;
use App\Modules\LeadSourcing\Actions\StartDiscoveryRunAction;
use App\Modules\LeadSourcing\Data\StartDiscoveryData;
use App\Modules\LeadSourcing\Http\Requests\StartDiscoveryRequest;
use App\Modules\LeadSourcing\Http\Resources\DiscoveryRunResource;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Versioned `/api/v1` access to discovery runs, consumed by the mobile app. Returns API Resources
 * only (never models) and delegates to the same Actions the web controller uses.
 */
final class DiscoveryController extends Controller
{
    public function index(PaginatedIndexRequest $request, GetDiscoveryRunsAction $getRuns): AnonymousResourceCollection
    {
        $team = $this->team($request);

        $this->authorize('view', $this->transientRun($team));

        // Runs accumulate for as long as a team keeps sourcing, so this is paged like the leads
        // list rather than returned whole, and `per_page` is read from the shared request so the
        // ceiling is the same one every other index endpoint enforces.
        return DiscoveryRunResource::collection($getRuns->handle($team, $request->perPage(15)));
    }

    public function store(StartDiscoveryRequest $request, StartDiscoveryRunAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);

        $this->authorize('create', $this->transientRun($team));

        $runs = $action->handle($team, StartDiscoveryData::fromValidated($request->validated()), $user);

        // 201: one run per selected source is created (queued); their leads arrive asynchronously.
        return DiscoveryRunResource::collection($runs)->response()->setStatusCode(201);
    }

    public function show(Request $request, int $run, FindDiscoveryRunAction $find): DiscoveryRunResource
    {
        $team = $this->team($request);
        $model = $find->handle($team, $run);

        $this->authorize('view', $model);

        return DiscoveryRunResource::make($model);
    }

    private function team(Request $request): Team
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        return $team;
    }

    private function transientRun(Team $team): DiscoveryRun
    {
        return (new DiscoveryRun)->forceFill(['team_id' => $team->getKey()]);
    }
}
