<?php

declare(strict_types=1);

namespace App\Modules\ICP\Support;

use App\Modules\Core\Models\Team;
use App\Modules\ICP\Data\CriterionData;
use App\Modules\ICP\Models\Icp;

/**
 * Shared criterion persistence for the ICP write Actions. Criteria are replaced wholesale on every
 * save (simple and reliable for the MVP editor, which posts the full set) and renumbered by input
 * order. Must be used inside an active team context + transaction (the Actions guarantee both).
 */
trait WritesIcpCriteria
{
    /**
     * Replace an ICP's criteria with the given set, in order.
     *
     * @param  list<CriterionData>  $criteria
     */
    protected function syncCriteria(Icp $icp, array $criteria): void
    {
        $icp->criteria()->delete();

        foreach ($criteria as $position => $criterion) {
            $icp->criteria()->create([
                'team_id' => $icp->team_id,
                'type' => $criterion->type,
                'value' => $criterion->value,
                'weight' => $criterion->type->isExclusion() ? null : $criterion->weight,
                'position' => $position,
            ]);
        }
    }

    /**
     * Lock the team's ICP rows for the rest of the transaction so concurrent create/activate
     * requests can't both pass the "is this the first / the active one" checks and leave the team
     * with two active ICPs (or none). A no-op on the (common) empty set.
     */
    protected function lockTeamIcps(Team $team): void
    {
        Icp::query()->where('team_id', $team->getKey())->lockForUpdate()->get();
    }

    /**
     * Clear the active flag on all of a team's ICPs (so exactly one can then be activated).
     */
    protected function deactivateTeamIcps(Team $team, ?int $exceptId = null): void
    {
        $query = Icp::query()->where('team_id', $team->getKey())->where('is_active', true);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $query->update(['is_active' => false]);
    }
}
