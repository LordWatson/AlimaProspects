<?php

declare(strict_types=1);

namespace App\Modules\ICP\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Events\IcpUpdated;
use App\Modules\ICP\Models\Icp;
use Illuminate\Support\Facades\DB;

/**
 * Delete an ICP (its criteria cascade). If the deleted ICP was the active one, the most recently
 * created remaining ICP is promoted to active so the team is never left scoring against nothing by
 * accident; an {@see IcpUpdated} event then fires `afterCommit` carrying the new active ICP (or null
 * when none remain). Deleting a non-active ICP changes no scoring definition, so no event fires.
 */
final class DeleteIcpAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Icp $icp, ?User $actor = null): void
    {
        $team = $icp->team;

        $this->teamContext->run($team, function () use ($icp, $team, $actor): void {
            DB::transaction(function () use ($icp, $team, $actor): void {
                // Lock the team's ICPs so a concurrent activate/delete can't race the promotion.
                Icp::query()->where('team_id', $team->getKey())->lockForUpdate()->get();

                $wasActive = $icp->is_active;
                $icpId = (int) $icp->getKey();

                $icp->delete();

                $this->audit->log('icp.deleted', null, ['icp_id' => $icpId, 'was_active' => $wasActive], $actor);

                if (! $wasActive) {
                    return;
                }

                $promoted = $this->promoteNextActive($team);

                DB::afterCommit(static fn () => event(new IcpUpdated(
                    (int) $team->getKey(),
                    $promoted !== null ? (int) $promoted->getKey() : null,
                )));
            });
        });
    }

    private function promoteNextActive(Team $team): ?Icp
    {
        $next = Icp::query()
            ->where('team_id', $team->getKey())
            ->latest('id')
            ->first();

        $next?->forceFill(['is_active' => true])->save();

        return $next;
    }
}
