<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\LeadSourcing\Http\Resources\LeadResource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\CreateLeadAction;
use App\Modules\LeadsWorkspace\Actions\FindLeadAction;
use App\Modules\LeadsWorkspace\Actions\ListLeadsAction;
use App\Modules\LeadsWorkspace\Http\Controllers\Web\Concerns\ResolvesWorkspace;
use App\Modules\LeadsWorkspace\Http\Requests\ListLeadsRequest;
use App\Modules\LeadsWorkspace\Http\Requests\StoreLeadRequest;
use App\Modules\LeadsWorkspace\Http\Resources\LeadDetailResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Versioned `/api/v1` access to the leads workspace, consumed by the mobile app (list → detail,
 * plus adding one lead by hand).
 * Returns API Resources only (never models), paginated, and delegates to the same Actions the web
 * controller uses so the two can't disagree about what a filter means.
 */
final class LeadController extends Controller
{
    use ResolvesWorkspace;

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Lead>>
     */
    public function index(ListLeadsRequest $request, ListLeadsAction $list): AnonymousResourceCollection
    {
        $team = $this->team($request);

        return LeadResource::collection($list->handle($team, $request->filters(), $request->perPage()));
    }

    /**
     * Add one lead by hand — the phone's version of the add-a-lead form, and the reason it is worth
     * having on the API at all: the member standing at a stand with a business card is on a phone,
     * not at a desk.
     *
     * `201` for a lead that didn't exist, `200` when the details were folded into one the team
     * already had, so a client can tell the two apart without re-reading the list.
     */
    public function store(StoreLeadRequest $request, CreateLeadAction $create): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('create', $this->transientLead($team));

        $result = $create->handle($team, $user, $request->lead());

        return LeadResource::make($result->lead->load(['pipelineStage', 'tags', 'assignments.user:id,name']))
            ->response()
            ->setStatusCode($result->merged ? 200 : 201);
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
}
