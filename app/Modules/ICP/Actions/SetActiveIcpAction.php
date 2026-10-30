<?php

declare(strict_types=1);

namespace App\Modules\ICP\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Events\IcpUpdated;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Support\WritesIcpCriteria;
use Illuminate\Support\Facades\DB;

/**
 * Make one ICP the team's active profile, deactivating the others. Always fires {@see IcpUpdated}
 * `afterCommit` — switching the active ICP changes what every lead is scored against. Idempotent:
 * re-activating the already-active ICP still re-affirms and emits the event.
 */
final class SetActiveIcpAction
{
    use WritesIcpCriteria;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Icp $icp, ?User $actor = null): Icp
    {
        $team = $icp->team;

        return $this->teamContext->run($team, fn (): Icp => DB::transaction(function () use ($icp, $team, $actor): Icp {
            $this->lockTeamIcps($team);
            $this->deactivateTeamIcps($team, exceptId: (int) $icp->getKey());

            $icp->forceFill(['is_active' => true])->save();

            $this->audit->log('icp.activated', $icp, [], $actor);

            DB::afterCommit(static fn () => event(IcpUpdated::forActive($icp)));

            return $icp;
        }));
    }
}
