<?php

declare(strict_types=1);

namespace App\Modules\Auth\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;

/**
 * Ownership double-check for team management (ADR 0006). The authoritative role is the
 * `team_user` pivot role, read via {@see User::roleOn()}. A non-member always fails.
 */
final class TeamPolicy
{
    /**
     * See the team and its members.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Update team settings (name, locale, timezone, market).
     */
    public function update(User $user, Team $team): bool
    {
        return $this->roleIn($user, $team, [TeamRole::Owner, TeamRole::Admin]);
    }

    /**
     * Delete the whole team — owners only.
     */
    public function delete(User $user, Team $team): bool
    {
        return $this->roleIn($user, $team, [TeamRole::Owner]);
    }

    /**
     * See the team's dashboard and reports. Any member, Read-only included: reporting is a view of
     * work the team already owns, and hiding the numbers from the person asked to read them would
     * make the role useless.
     */
    public function viewReports(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Invite, resend, revoke, remove members and change roles.
     */
    public function manageMembers(User $user, Team $team): bool
    {
        return $this->roleIn($user, $team, [TeamRole::Owner, TeamRole::Admin]);
    }

    /**
     * @param  list<TeamRole>  $roles
     */
    private function roleIn(User $user, Team $team, array $roles): bool
    {
        $role = $user->roleOn($team);

        return $role !== null && in_array($role, $roles, strict: true);
    }
}
