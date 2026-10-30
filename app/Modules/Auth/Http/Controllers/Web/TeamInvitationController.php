<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Actions\InviteTeamMemberAction;
use App\Modules\Auth\Actions\ResendInvitationAction;
use App\Modules\Auth\Actions\RevokeInvitationAction;
use App\Modules\Auth\Data\InviteMemberData;
use App\Modules\Auth\Http\Requests\InviteMemberRequest;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Core\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class TeamInvitationController extends Controller
{
    public function store(InviteMemberRequest $request, InviteTeamMemberAction $invite): RedirectResponse
    {
        $team = $this->authorizedTeam($request);

        /** @var User $user */
        $user = $request->user();

        $invite->handle($team, InviteMemberData::from($request->validated()), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return back();
    }

    public function resend(Request $request, TeamInvitation $invitation, ResendInvitationAction $resend): RedirectResponse
    {
        $this->authorizedTeam($request);

        $resend->handle($invitation, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation resent.')]);

        return back();
    }

    public function destroy(Request $request, TeamInvitation $invitation, RevokeInvitationAction $revoke): RedirectResponse
    {
        $this->authorizedTeam($request);

        $revoke->handle($invitation, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation revoked.')]);

        return back();
    }

    /**
     * Resolve the viewer's active team and confirm they may manage its members. Bound
     * invitations are already tenant-scoped to this team by the global scope.
     */
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
