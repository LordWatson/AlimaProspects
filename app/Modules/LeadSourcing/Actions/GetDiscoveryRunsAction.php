<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Lists a team's discovery runs, newest first, with their lead counts — for the discovery index and
 * the `/api/v1` runs list. Scoped by team via {@see TeamContext}; the ICP is eager-loaded so the UI
 * can name it without an N+1.
 */
final class GetDiscoveryRunsAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @return LengthAwarePaginator<int, DiscoveryRun>
     */
    public function handle(Team $team, int $perPage = 15): LengthAwarePaginator
    {
        return $this->teamContext->run($team, fn (): LengthAwarePaginator => DiscoveryRun::query()
            ->where('team_id', $team->getKey())
            ->with('icp:id,name')
            ->orderByDesc('id')
            ->paginate($perPage));
    }
}
