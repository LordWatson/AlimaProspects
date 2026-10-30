<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sets the left-to-right order of a team's board (Phase 10b).
 *
 * Takes the ids in the order they should appear and rewrites `position` for all of them, which is
 * why `position` has no unique index: a swap has to pass through an intermediate state. Ids that
 * aren't the team's own are ignored rather than rejected, and stages the caller left out keep their
 * relative order on the end — so a stale board that a teammate has since added a column to
 * reorders what it knew about instead of failing or losing the new column.
 */
final class ReorderPipelineStagesAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<int>  $orderedIds
     * @return Collection<int, PipelineStage>
     */
    public function handle(Team $team, User $actor, array $orderedIds): Collection
    {
        return DB::transaction(function () use ($team, $actor, $orderedIds): Collection {
            $stages = PipelineStage::query()
                ->where('team_id', $team->getKey())
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            $requested = array_values(array_filter(
                array_unique($orderedIds),
                static fn (int $id): bool => $stages->contains('id', $id),
            ));

            $trailing = $stages
                ->reject(fn (PipelineStage $stage): bool => in_array((int) $stage->getKey(), $requested, strict: true))
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            foreach ([...$requested, ...$trailing] as $position => $id) {
                PipelineStage::query()
                    ->where('team_id', $team->getKey())
                    ->whereKey($id)
                    ->update(['position' => $position]);
            }

            $this->audit->log('leads.stages_reordered', null, ['count' => count($requested)], $actor);

            return PipelineStage::query()
                ->where('team_id', $team->getKey())
                ->orderBy('position')
                ->orderBy('id')
                ->get();
        });
    }
}
