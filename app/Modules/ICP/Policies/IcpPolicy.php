<?php

declare(strict_types=1);

namespace App\Modules\ICP\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\ICP\Models\Icp;

/**
 * Ownership double-check for ICPs (ADR 0006). Any member of the ICP's team may view it; defining,
 * editing, activating or deleting an ICP is team setup, so it's limited to Owners and Admins. A
 * non-member always fails. Authority is the `team_user` pivot role via {@see User::roleOn()}.
 *
 * For `create` the controller authorises against a transient ICP carrying the active team's id so
 * the team resolves without a stored row.
 */
final class IcpPolicy
{
    public function view(User $user, Icp $icp): bool
    {
        return $user->belongsToTeam($icp->team);
    }

    public function create(User $user, Icp $icp): bool
    {
        return $this->canManage($user, $icp);
    }

    public function update(User $user, Icp $icp): bool
    {
        return $this->canManage($user, $icp);
    }

    public function delete(User $user, Icp $icp): bool
    {
        return $this->canManage($user, $icp);
    }

    private function canManage(User $user, Icp $icp): bool
    {
        $role = $user->roleOn($icp->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
