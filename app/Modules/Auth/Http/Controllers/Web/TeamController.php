<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Actions\CreateTeamAction;
use App\Modules\Auth\Actions\UpdateTeamAction;
use App\Modules\Auth\Data\CreateTeamData;
use App\Modules\Auth\Http\Requests\CreateTeamRequest;
use App\Modules\Auth\Http\Requests\UpdateTeamRequest;
use App\Modules\Core\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class TeamController extends Controller
{
    public function store(CreateTeamRequest $request, CreateTeamAction $createTeam): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $createTeam->handle($user, CreateTeamData::from($request->validated()), switchTo: true);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team created. You\'re now working in it.')]);

        return to_route('team.settings');
    }

    public function update(UpdateTeamRequest $request, UpdateTeamAction $updateTeam): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        $this->authorize('update', $team);

        $updateTeam->handle($team, CreateTeamData::from($request->validated()), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team settings saved.')]);

        return back();
    }
}
