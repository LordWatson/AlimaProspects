<?php

declare(strict_types=1);

namespace App\Modules\ICP\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Data\IcpData;
use App\Modules\ICP\Events\IcpUpdated;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Support\WritesIcpCriteria;
use Illuminate\Support\Facades\DB;

/**
 * Create a new ICP for a team with its criteria. The first ICP a team creates is made active
 * automatically; otherwise it becomes active only if the input asks for it (deactivating any
 * previous active ICP). When the new ICP ends up active, an {@see IcpUpdated} event fires
 * `afterCommit` so Scoring can (re)score against the new definition.
 */
final class CreateIcpAction
{
    use WritesIcpCriteria;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Team $team, IcpData $data, ?User $actor = null): Icp
    {
        return $this->teamContext->run($team, fn (): Icp => DB::transaction(function () use ($team, $data, $actor): Icp {
            $this->lockTeamIcps($team);

            $isFirst = Icp::query()->where('team_id', $team->getKey())->count() === 0;
            $shouldActivate = $data->isActive || $isFirst;

            if ($shouldActivate) {
                $this->deactivateTeamIcps($team);
            }

            $icp = new Icp([
                'name' => $data->name,
                'description' => $data->description,
            ]);
            $icp->is_active = $shouldActivate;
            $icp->team_id = (int) $team->getKey();
            $icp->save();

            $this->syncCriteria($icp, $data->criteria);

            $this->audit->log('icp.created', $icp, [
                'is_active' => $icp->is_active,
                'criteria' => count($data->criteria),
            ], $actor);

            if ($icp->is_active) {
                DB::afterCommit(static fn () => event(IcpUpdated::forActive($icp)));
            }

            return $icp->load('criteria');
        }));
    }
}
