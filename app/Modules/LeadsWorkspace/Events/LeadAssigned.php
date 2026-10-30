<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Events;

use App\Modules\LeadsWorkspace\Actions\SetLeadAssigneesAction;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired (after commit) when members are newly put on a lead ({@see SetLeadAssigneesAction}).
 * Notifications (Phase 12) listens for this to tell them.
 *
 * Carries only the **newly added** members, not the whole assignee set: a member already on the
 * lead has not been given anything, and telling them again every time a colleague joins would be
 * noise. Ids only — a listener re-reads inside the team's context.
 *
 * An internal, non-broadcast event: it is addressed to specific people, and the team channel would
 * show one member's assignment to everybody.
 */
final class LeadAssigned
{
    use Dispatchable;

    /**
     * @param  list<int>  $userIds  The members newly added.
     */
    public function __construct(
        public readonly int $teamId,
        public readonly int $leadId,
        public readonly array $userIds,
        /** Who did the assigning, so the notification can say "Dana gave you Acme Ltd". */
        public readonly ?int $actorId = null,
    ) {}
}
