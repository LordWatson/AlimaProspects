<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Enrichment\Models\LeadContact;

/**
 * Ownership double-check for a contact (ADR 0006), and the one place the reveal's authorisation
 * lives (PL3).
 *
 * - **view** — any member of the owning team, Read-only included. The masked form is still a view.
 * - **reveal** — any member except Read-only. A reveal **spends the team's allowance**, which is
 *   the line `LeadPolicy` already draws between reading a lead and doing something to it. It is
 *   deliberately *not* Owner/Admin-only like an export: a member working a lead has to be able to
 *   see the address they are about to write to, and a product that needed an admin for that would
 *   be a product nobody uses.
 * - **enrich** — the same. Asking a vendor to look spends the same allowance.
 *
 * Nothing here can delete a contact: a prospect's record is removed by retention or by a DSAR
 * erasure, never by a member tidying up — which is what keeps `outreach_suppressions` meaningful.
 */
final class LeadContactPolicy
{
    public function view(User $user, LeadContact $contact): bool
    {
        return $user->belongsToTeam($contact->team);
    }

    public function reveal(User $user, LeadContact $contact): bool
    {
        return $this->canSpend($user, $contact);
    }

    public function enrich(User $user, LeadContact $contact): bool
    {
        return $this->canSpend($user, $contact);
    }

    private function canSpend(User $user, LeadContact $contact): bool
    {
        $role = $user->roleOn($contact->team);

        return $role !== null && $role !== TeamRole::ReadOnly;
    }
}
