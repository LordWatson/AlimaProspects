<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\LeadSourcing\Models\LeadEvidence;

/**
 * Ownership double-check for an item of evidence (ADR 0006). The global scope hides other teams'
 * rows; this is the authorisation check beside it, and a non-member always fails.
 *
 * - **view** — any member of the owning team, Read-only included. Evidence is the explanation of a
 *   lead the member can already see, and a figure nobody is allowed to check is worse than no
 *   figure.
 * - **flag** — any member except Read-only. Flagging a fact as wrong lowers the lead's confidence
 *   for the whole team, which is work rather than reading; it is the same line {@see LeadPolicy}
 *   draws for everything else a member does *to* a lead.
 *
 * Nothing here can create or delete evidence. A reason is written by the pipeline that found it and
 * removed by retention, never by hand — a team that could delete an inconvenient fact about a
 * prospect would have a confidence figure that measured its own optimism.
 */
final class LeadEvidencePolicy
{
    public function view(User $user, LeadEvidence $evidence): bool
    {
        return $user->belongsToTeam($evidence->team);
    }

    public function flag(User $user, LeadEvidence $evidence): bool
    {
        $role = $user->roleOn($evidence->team);

        return $role !== null && $role !== TeamRole::ReadOnly;
    }
}
