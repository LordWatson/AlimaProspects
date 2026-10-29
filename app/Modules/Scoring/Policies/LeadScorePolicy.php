<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Scoring\Models\LeadScore;

/**
 * Ownership double-check for lead scores (ADR 0006).
 *
 * - **view** — any member of the owning team.
 * - **rescore** — Owner/Admin only: it spends AI credit against the team's allowance.
 * - **giveFeedback** — any member except Read-only, since a verdict is a write that will tune how
 *   the team's leads are graded.
 *
 * Role comes from the `team_user` pivot via {@see User::roleOn()}. A non-member always fails.
 */
final class LeadScorePolicy
{
    public function view(User $user, LeadScore $score): bool
    {
        return $user->belongsToTeam($score->team);
    }

    public function rescore(User $user, LeadScore $score): bool
    {
        $role = $user->roleOn($score->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }

    public function giveFeedback(User $user, LeadScore $score): bool
    {
        $role = $user->roleOn($score->team);

        return $role !== null && $role !== TeamRole::ReadOnly;
    }
}
