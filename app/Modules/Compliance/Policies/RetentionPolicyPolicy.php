<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Policies;

use App\Models\User;
use App\Modules\Compliance\Models\RetentionPolicy;
use App\Modules\Core\Enums\TeamRole;

/**
 * Ownership double-check for a team's retention windows (ADR 0006, docs/compliance.md §3.4).
 *
 * **Any member may see the windows, only Owners and Admins may change them.** Everyone is entitled
 * to know how long their team keeps data — it is the promise their own outreach relies on — but the
 * form's output deletes people's records, which is not a thing to leave to whoever opens the screen.
 *
 * A non-member always fails. Role comes from the `team_user` pivot via {@see User::roleOn()}.
 */
final class RetentionPolicyPolicy
{
    public function view(User $user, RetentionPolicy $policy): bool
    {
        return $user->belongsToTeam($policy->team);
    }

    public function update(User $user, RetentionPolicy $policy): bool
    {
        $role = $user->roleOn($policy->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
