<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\LeadsWorkspace\Models\PipelineStage;

/**
 * Ownership double-check for the team's board (ADR 0006).
 *
 * - **view** — any member of the owning team.
 * - **manage** — Owner/Admin. The board is shared furniture: renaming, reordering, adding or
 *   removing a column changes how every member sees every lead, which is a different thing from
 *   moving one lead between columns (that is `LeadPolicy::work`, open to any member).
 */
final class PipelineStagePolicy
{
    public function view(User $user, PipelineStage $stage): bool
    {
        return $user->belongsToTeam($stage->team);
    }

    public function manage(User $user, PipelineStage $stage): bool
    {
        $role = $user->roleOn($stage->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
