<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Http\Resources\LeadResource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\FindLeadAction;
use App\Modules\LeadsWorkspace\Actions\ListLeadsAction;
use App\Modules\LeadsWorkspace\Http\Requests\ListLeadsRequest;
use App\Modules\LeadsWorkspace\Http\Resources\LeadDetailResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Versioned `/api/v1` access to the leads workspace, consumed by the mobile app (list → detail).
 * Returns API Resources only (never models), paginated, and delegates to the same Actions the web
 * controller uses so the two can't disagree about what a filter means.
 */
final class LeadController extends Controller
{
    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Lead>>
     */
    public function index(ListLeadsRequest $request, ListLeadsAction $list): AnonymousResourceCollection
    {
        $team = $this->team($request);

        return LeadResource::collection($list->handle($team, $request->filters(), $request->perPage()));
    }

    /**
     * The lead, its score and its timeline in one {@see LeadDetailResource}.
     */
    public function show(Request $request, int $lead, FindLeadAction $find): LeadDetailResource
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $find->handle($team, $lead, (int) $user->getKey());

        $this->authorize('view', $model);

        return LeadDetailResource::make($model);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function team(Request $request): Team
    {
        /** @var Team $team */
        $team = $this->user($request)->currentTeam()->firstOrFail();

        return $team;
    }
}
