<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Policies;

use App\Models\User;
use App\Modules\AiInsights\Models\Insight;
use App\Modules\Core\Enums\TeamRole;

/**
 * Ownership double-check for insights (ADR 0006). Any team member — Read-only included — may read
 * the team's insights; Owners, Admins and Members may dismiss one. Read-only may not, because
 * dismissing changes what the rest of the team sees: an insight belongs to the team, not to the
 * reader, and there is no per-member copy of it.
 *
 * A non-member always fails. Role comes from the `team_user` pivot via {@see User::roleOn()}.
 */
final class InsightPolicy
{
    public function view(User $user, Insight $insight): bool
    {
        return $user->belongsToTeam($insight->team);
    }

    public function dismiss(User $user, Insight $insight): bool
    {
        $role = $user->roleOn($insight->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin, TeamRole::Member], strict: true);
    }
}
