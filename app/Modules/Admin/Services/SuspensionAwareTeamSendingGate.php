<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Outreach\Contracts\TeamSendingGate;

/**
 * A suspended team sends nothing (Phase 19a).
 *
 * The third of the three guards suspension bites in, and the one that matters most: sourcing and
 * spending cost us money, but sending reaches a member of the public. A suspended team is suspended
 * because something is wrong — unpaid, or complained about — and the one thing that must stop
 * immediately in either case is messages going out of our sending domain on their behalf.
 *
 * Decorates the bound gate rather than replacing it, so Outreach's own deployment-level switch and
 * anything later bound in front of it still apply.
 */
final class SuspensionAwareTeamSendingGate implements TeamSendingGate
{
    public function __construct(
        private readonly TeamSendingGate $inner,
        private readonly TeamSuspension $suspension,
    ) {}

    public function mayTeamSend(Team $team): bool
    {
        if ($this->suspension->isSuspended($team)) {
            return false;
        }

        return $this->inner->mayTeamSend($team);
    }
}
