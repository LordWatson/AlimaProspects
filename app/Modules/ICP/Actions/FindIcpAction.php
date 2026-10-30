<?php

declare(strict_types=1);

namespace App\Modules\ICP\Actions;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;

/**
 * Resolve a single ICP by id, scoped to the team. Run inside the team context so the
 * {@see BelongsToTeam} global scope hides other teams' ICPs — a request
 * for one that isn't the team's own 404s rather than binding cross-tenant. Policies still
 * double-check ownership at the controller.
 */
final class FindIcpAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    public function handle(Team $team, int $id): Icp
    {
        // Eager-load `team` as well as `criteria`: the write Actions read `$icp->team`, and
        // preventLazyLoading (shouldBeStrict) is on outside production.
        return $this->teamContext->run($team, fn (): Icp => Icp::query()
            ->where('team_id', $team->getKey())
            ->with(['criteria', 'team'])
            ->findOrFail($id));
    }
}
