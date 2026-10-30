<?php

declare(strict_types=1);

namespace App\Modules\ICP\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;

/**
 * Resolve a team's **active** ICP — the one definition that drives discovery and scoring — with its
 * criteria and team eager-loaded, or null when the team hasn't activated one. A team has at most
 * one active ICP (the write Actions enforce it under a row lock).
 *
 * Shared by Scoring and the discovery/score screens so none of them query for it directly.
 */
final class FindActiveIcpAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    public function handle(Team|int $team): ?Icp
    {
        $teamId = $team instanceof Team ? (int) $team->getKey() : $team;

        return $this->teamContext->run($teamId, fn (): ?Icp => Icp::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            // `criteria` for the scorer, `team` for the policies — both read eagerly so a lazy
            // access can't trip preventLazyLoading.
            ->with(['criteria', 'team'])
            ->first());
    }
}
