<?php

declare(strict_types=1);

namespace App\Modules\ICP\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\ICP\Actions\CreateIcpAction;
use App\Modules\ICP\Actions\DeleteIcpAction;
use App\Modules\ICP\Actions\FindIcpAction;
use App\Modules\ICP\Actions\GetIcpsAction;
use App\Modules\ICP\Actions\SetActiveIcpAction;
use App\Modules\ICP\Actions\UpdateIcpAction;
use App\Modules\ICP\Data\IcpData;
use App\Modules\ICP\Http\Requests\SaveIcpRequest;
use App\Modules\ICP\Http\Resources\IcpResource;
use App\Modules\ICP\Models\Icp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Versioned `/api/v1` CRUD for ICPs, consumed by the mobile app and integrations. Returns API
 * Resources only (never models) and delegates to the same Actions the web controller uses.
 */
final class IcpController extends Controller
{
    public function index(Request $request, GetIcpsAction $getIcps): AnonymousResourceCollection
    {
        $team = $this->team($request);

        $this->authorize('view', $this->transientIcp($team));

        return IcpResource::collection($getIcps->handle($team));
    }

    public function show(Request $request, int $icp, FindIcpAction $find): IcpResource
    {
        $team = $this->team($request);
        $model = $find->handle($team, $icp);

        $this->authorize('view', $model);

        return IcpResource::make($model);
    }

    public function store(SaveIcpRequest $request, CreateIcpAction $action): IcpResource
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);

        $this->authorize('create', $this->transientIcp($team));

        $icp = $action->handle($team, IcpData::fromValidated($request->validated()), $user);

        return IcpResource::make($icp);
    }

    public function update(SaveIcpRequest $request, int $icp, FindIcpAction $find, UpdateIcpAction $action): IcpResource
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);
        $model = $find->handle($team, $icp);

        $this->authorize('update', $model);

        return IcpResource::make($action->handle($model, IcpData::fromValidated($request->validated()), $user));
    }

    public function activate(Request $request, int $icp, FindIcpAction $find, SetActiveIcpAction $action): IcpResource
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);
        $model = $find->handle($team, $icp);

        $this->authorize('update', $model);

        $action->handle($model, $user);

        return IcpResource::make($model->load('criteria'));
    }

    public function destroy(Request $request, int $icp, FindIcpAction $find, DeleteIcpAction $action): Response
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);
        $model = $find->handle($team, $icp);

        $this->authorize('delete', $model);

        $action->handle($model, $user);

        return response()->noContent();
    }

    private function team(Request $request): Team
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        return $team;
    }

    private function transientIcp(Team $team): Icp
    {
        return (new Icp)->forceFill(['team_id' => $team->getKey()]);
    }
}
