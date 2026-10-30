<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Actions;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\DiscoveryRun;

/**
 * Resolve a single discovery run by id, scoped to the team. Run inside the team context so the
 * {@see BelongsToTeam} global scope hides other teams' runs — a request for one that isn't the
 * team's own 404s rather than binding cross-tenant. Policies still double-check ownership at the
 * controller. Eager-loads the ICP and the run's leads — each with its score against the active ICP,
 * so the detail screen can show grades without an N+1.
 */
final class FindDiscoveryRunAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    public function handle(Team $team, int $id): DiscoveryRun
    {
        return $this->teamContext->run($team, fn (): DiscoveryRun => DiscoveryRun::query()
            ->where('team_id', $team->getKey())
            ->with([
                'icp:id,name',
                'leads' => fn ($q) => $q->orderByDesc('id'),
                'leads.activeScore',
            ])
            ->findOrFail($id));
    }
}
