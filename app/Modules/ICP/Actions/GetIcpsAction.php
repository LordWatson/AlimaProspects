<?php

declare(strict_types=1);

namespace App\Modules\ICP\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read a team's ICPs (active first, then newest), with their criteria eager-loaded. Shared by the
 * web and API controllers so neither queries directly. Criteria are eager-loaded to avoid N+1.
 */
final class GetIcpsAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @return Collection<int, Icp>
     */
    public function handle(Team $team): Collection
    {
        return $this->teamContext->run($team, fn (): Collection => Icp::query()
            ->where('team_id', $team->getKey())
            ->with('criteria')
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get());
    }
}
