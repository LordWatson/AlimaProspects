<?php

declare(strict_types=1);

namespace App\Modules\ICP\Events;

use App\Modules\ICP\Models\Icp;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired whenever the definition that drives scoring changes for a team: the active ICP's criteria
 * or weights were edited, a different ICP was made active, or the active ICP was deleted. Scoring &
 * Matching (Phase 9) listens for this to re-score the team's leads. Dispatched `afterCommit` so
 * listeners never see uncommitted state.
 *
 * `activeIcpId` is the team's active ICP after the change, or null when the team now has none.
 */
final class IcpUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly int $teamId,
        public readonly ?int $activeIcpId,
    ) {}

    public static function forActive(Icp $icp): self
    {
        return new self((int) $icp->team_id, (int) $icp->getKey());
    }
}
