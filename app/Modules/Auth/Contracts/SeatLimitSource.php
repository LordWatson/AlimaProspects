<?php

declare(strict_types=1);

namespace App\Modules\Auth\Contracts;

use App\Modules\Auth\Actions\InviteTeamMemberAction;
use App\Modules\Auth\Services\UnlimitedSeatLimit;
use App\Modules\Core\Models\Team;

/**
 * How many people a team may have. Reported by Billing (Phase 16); until it is bound, the
 * {@see UnlimitedSeatLimit} default applies and nobody is turned away.
 *
 * The seam reports the **limit only** — counting who is in a team, and who holds a pending
 * invitation to it, is Auth's own business and Billing has no reason to know how invitations work.
 * That division is what keeps the seat rule a single `if` in {@see InviteTeamMemberAction} rather
 * than a second implementation of team membership living in another module. Mirrors AI Platform's
 * `CreditSource` and Lead Sourcing's `LeadQuotaSource`.
 */
interface SeatLimitSource
{
    /**
     * Seats included in the team's plan, or null for no limit.
     */
    public function seatsFor(Team $team): ?int;
}
