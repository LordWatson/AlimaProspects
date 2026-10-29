<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Policies;

use App\Models\User;
use App\Modules\LeadSourcing\Models\Lead;

/**
 * Ownership double-check for leads (ADR 0006). Any member of the owning team may view a lead and
 * what the platform knows about it; a non-member always fails. The {@see Lead} global scope hides
 * other teams' rows, but a global scope is not an authorisation check — this is.
 *
 * Mutating a lead (stage, tags, notes, assignment) arrives with the Leads Workspace (Phase 10) and
 * will add its own abilities here.
 */
final class LeadPolicy
{
    public function view(User $user, Lead $lead): bool
    {
        return $user->belongsToTeam($lead->team);
    }
}
