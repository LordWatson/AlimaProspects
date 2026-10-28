<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Actions\SwitchTeamAction;
use App\Modules\Auth\Http\Resources\TeamResource;
use App\Modules\Core\Models\Team;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TeamSwitchController extends Controller
{
    public function __invoke(Request $request, Team $team, SwitchTeamAction $switchTeam): TeamResource
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($switchTeam->handle($user, $team), Response::HTTP_FORBIDDEN);

        return TeamResource::make($team);
    }
}
