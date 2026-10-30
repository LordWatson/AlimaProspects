<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Onboarding\Models\BusinessProfile;

/**
 * Ownership double-check for the Business Profile (ADR 0006). The profile is team-level setup, so
 * editing it mirrors team settings: Owners and Admins may complete/change it; any member may view
 * it; a non-member always fails. The authoritative role is the `team_user` pivot role via
 * {@see User::roleOn()}.
 */
final class BusinessProfilePolicy
{
    public function view(User $user, BusinessProfile $profile): bool
    {
        return $user->belongsToTeam($profile->team);
    }

    public function update(User $user, BusinessProfile $profile): bool
    {
        $role = $user->roleOn($profile->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
