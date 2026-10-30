<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Actions\RemoveTeamMemberAction;
use App\Modules\Auth\Actions\UpdateTeamMemberRoleAction;
use App\Modules\Auth\Http\Requests\UpdateMemberRoleRequest;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class TeamMemberController extends Controller
{
    public function update(UpdateMemberRoleRequest $request, User $member, UpdateTeamMemberRoleAction $updateRole): RedirectResponse
    {
        $team = $this->authorizedTeam($request);

        $role = TeamRole::from((string) $request->validated('role'));

        $updateRole->handle($team, $member, $role, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return back();
    }

    public function destroy(Request $request, User $member, RemoveTeamMemberAction $remove): RedirectResponse
    {
        $team = $this->authorizedTeam($request);

        $remove->handle($team, $member, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return back();
    }

    private function authorizedTeam(Request $request): Team
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        $this->authorize('manageMembers', $team);

        return $team;
    }
}
