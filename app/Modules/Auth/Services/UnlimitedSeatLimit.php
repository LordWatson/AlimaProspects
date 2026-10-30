<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Contracts\SeatLimitSource;
use App\Modules\Core\Models\Team;

/**
 * The default seat source: no limit. Billing binds a plan-backed one over it (Phase 16).
 *
 * Deliberately permissive rather than restrictive. A seat rule that defaults to *some* number would
 * mean an installation without Billing configured starts refusing invitations for a plan nobody is
 * on, and the failure would look like a bug in team management rather than a missing binding.
 */
final class UnlimitedSeatLimit implements SeatLimitSource
{
    public function seatsFor(Team $team): ?int
    {
        return null;
    }
}
