<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Services;

use App\Modules\Auth\Services\UnlimitedSeatLimit;
use App\Modules\Core\Models\Team;
use App\Modules\Outreach\Contracts\TeamSendingGate;

/**
 * The default team sending gate: everybody may send.
 *
 * Deliberately permissive rather than restrictive, for the same reason as Auth's
 * {@see UnlimitedSeatLimit}. A default that refused would mean an
 * installation with the Admin module unbound starts blocking outreach for a suspension nobody
 * applied, and the failure would read as a bug in Outreach rather than a missing binding.
 *
 * Admin (Phase 19a) binds a suspension-aware gate over it.
 */
final class AlwaysAllowTeamSending implements TeamSendingGate
{
    public function mayTeamSend(Team $team): bool
    {
        return true;
    }
}
