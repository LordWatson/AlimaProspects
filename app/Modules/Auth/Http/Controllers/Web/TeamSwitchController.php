<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Actions\SwitchTeamAction;
use App\Modules\Core\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class TeamSwitchController extends Controller
{
    public function __invoke(Request $request, Team $team, SwitchTeamAction $switchTeam): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($switchTeam->handle($user, $team), Response::HTTP_FORBIDDEN);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Switched to :team.', ['team' => $team->name])]);

        return back();
    }
}
