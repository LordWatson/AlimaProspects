<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;

/**
 * Whether a tenant is suspended — asked by the guards, not by middleware (Phase 19a).
 *
 * Memoised per team id for the life of the request or job, because the three decorators that
 * consult it (credits, lead quota, outreach sending) are each asked inside loops: a discovery run
 * checks its quota per batch, and a send checks per recipient. One query per context, however many
 * times it is asked.
 *
 * Deliberately a tiny class of its own rather than a call to `$team->isSuspended()` at each site.
 * The decorators receive a team **id** in some paths and a model in others, and a service that can
 * answer both is what keeps "is this tenant suspended?" from being re-implemented three times with
 * three different ideas about what to do when the team has since been deleted. (A deleted team is
 * not suspended: there is nothing left to stop.)
 */
final class TeamSuspension
{
    /**
     * @var array<int, bool>
     */
    private array $memo = [];

    public function isSuspended(Team|int $team): bool
    {
        if ($team instanceof Team) {
            // The model in hand is more current than anything cached, and loading it again would be
            // a query for an answer already on the object.
            return $this->memo[(int) $team->getKey()] = $team->isSuspended();
        }

        return $this->memo[$team] ??= Team::query()
            ->whereKey($team)
            ->whereNotNull('suspended_at')
            ->exists();
    }

    /**
     * Drop the memo. A long-lived queue worker must not answer for the team it saw three jobs ago —
     * the same obligation {@see TeamContext} has.
     */
    public function forget(): void
    {
        $this->memo = [];
    }
}
