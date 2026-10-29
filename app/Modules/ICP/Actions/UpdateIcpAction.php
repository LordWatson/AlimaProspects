<?php

declare(strict_types=1);

namespace App\Modules\ICP\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Data\IcpData;
use App\Modules\ICP\Events\IcpUpdated;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Support\WritesIcpCriteria;
use Illuminate\Support\Facades\DB;

/**
 * Update an ICP's name, description, active flag and criteria. If the input activates it, any other
 * active ICP for the team is deactivated. Whenever the ICP is active after the save, an
 * {@see IcpUpdated} event fires `afterCommit` (its criteria/weights may have changed, so leads must
 * be re-scored).
 */
final class UpdateIcpAction
{
    use WritesIcpCriteria;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Icp $icp, IcpData $data, ?User $actor = null): Icp
    {
        $team = $icp->team;

        return $this->teamContext->run($team, fn (): Icp => DB::transaction(function () use ($icp, $team, $data, $actor): Icp {
            $this->lockTeamIcps($team);
            $wasActive = $icp->is_active;

            if ($data->isActive) {
                $this->deactivateTeamIcps($team, exceptId: (int) $icp->getKey());
            }

            $icp->forceFill([
                'name' => $data->name,
                'description' => $data->description,
                'is_active' => $data->isActive,
            ])->save();

            $this->syncCriteria($icp, $data->criteria);

            $this->audit->log('icp.updated', $icp, [
                'is_active' => $icp->is_active,
                'criteria' => count($data->criteria),
            ], $actor);

            // Re-score whenever the active definition changes. That includes this ICP being
            // deactivated (active → inactive leaves the team with no active ICP): emit a null
            // active id so Scoring clears its scoring against the old definition.
            if ($icp->is_active) {
                DB::afterCommit(static fn () => event(IcpUpdated::forActive($icp)));
            } elseif ($wasActive) {
                $teamId = (int) $team->getKey();
                DB::afterCommit(static fn () => event(new IcpUpdated($teamId, null)));
            }

            return $icp->load('criteria');
        }));
    }
}
