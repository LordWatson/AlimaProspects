<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Contracts;

use App\Modules\Core\Models\Team;
use App\Modules\Outreach\Enums\OutreachBlockReason;
use App\Modules\Outreach\Services\AlwaysAllowTeamSending;
use App\Modules\Outreach\Services\OutreachGuard;

/**
 * Whether a team is permitted to send at all, irrespective of which prospect or channel.
 *
 * The third seam in {@see OutreachGuard}, beside the lead's own restriction and the suppression
 * list, and the one that answers a question about **us and the team** rather than about the
 * recipient. `config('outreach.sending_enabled')` was already a deployment-wide version of it; this
 * is the per-team one.
 *
 * It exists because team suspension (Phase 19a) must stop sending, and the two obvious shortcuts
 * are both wrong. Binding over {@see LeadRestrictionResolver} would have the guard tell a member
 * "this prospect may not be approached", which is a lie about somebody else's legal position and
 * would be recorded as one. Putting the check in middleware would miss the queued and scheduled
 * sends that have no request behind them — ADR 0012's argument, and the reason plan limits live in
 * guards too.
 *
 * The default binding is {@see AlwaysAllowTeamSending}: permissive, like Auth's seat stub and Lead
 * Sourcing's quota stub, so an installation without the Admin module bound does not start refusing
 * sends for a reason nobody can find.
 */
interface TeamSendingGate
{
    /**
     * False stops every sendable channel for this team, with
     * {@see OutreachBlockReason::TeamSuspended}.
     */
    public function mayTeamSend(Team $team): bool;
}
