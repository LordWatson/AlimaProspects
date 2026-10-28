<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Actions\CreateTeamAction;
use App\Modules\Auth\Data\CreateTeamData;
use App\Modules\Auth\Http\Requests\CreateTeamRequest;
use App\Modules\Auth\Http\Resources\TeamResource;
use App\Modules\Core\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TeamController extends Controller
{
    /**
     * Teams the authenticated user belongs to (with their role on each).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return TeamResource::collection($user->teams()->orderBy('name')->get());
    }

    public function store(CreateTeamRequest $request, CreateTeamAction $createTeam): TeamResource
    {
        /** @var User $user */
        $user = $request->user();

        $team = $createTeam->handle($user, CreateTeamData::from($request->validated()));

        return TeamResource::make($team->fresh());
    }

    /**
     * The authenticated user's active team.
     */
    public function current(Request $request): TeamResource
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        return TeamResource::make($team);
    }
}
