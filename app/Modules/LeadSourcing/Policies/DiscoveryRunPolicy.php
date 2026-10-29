<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\LeadSourcing\Models\DiscoveryRun;

/**
 * Ownership double-check for discovery runs (ADR 0006). Any team member may view the team's runs
 * and the leads they produced; only Owners and Admins may start a run (it spends provider/plan
 * quota). A non-member always fails. Role comes from the `team_user` pivot via {@see User::roleOn()}.
 */
final class DiscoveryRunPolicy
{
    public function view(User $user, DiscoveryRun $run): bool
    {
        return $user->belongsToTeam($run->team);
    }

    public function create(User $user, DiscoveryRun $run): bool
    {
        $role = $user->roleOn($run->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
