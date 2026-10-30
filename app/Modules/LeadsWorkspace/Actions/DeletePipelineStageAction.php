<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Exceptions\LeadsWorkspaceException;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Support\Facades\DB;

/**
 * Removes a stage from a team's board (Phase 10b).
 *
 * **Leads move, they never go.** Everything in the stage is placed in another column first, so
 * removing a column can't quietly take a team's leads with it — the `nullOnDelete` foreign key is
 * the backstop, not the plan. Two stages can't be removed: the last one (a board needs somewhere
 * for leads to be) and the default one (another column has to take that job first).
 */
final class DeletePipelineStageAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  PipelineStage|null  $moveTo  Where the stage's leads go. Defaults to the team's
     *                                      default stage, which is where an unplaced lead already
     *                                      appears, so the move is the least surprising one.
     */
    public function handle(PipelineStage $stage, User $actor, ?PipelineStage $moveTo = null): void
    {
        DB::transaction(function () use ($stage, $actor, $moveTo): void {
            $remaining = PipelineStage::query()
                ->where('team_id', $stage->team_id)
                ->whereKeyNot($stage->getKey())
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            if ($remaining->isEmpty()) {
                throw LeadsWorkspaceException::lastStage();
            }

            if ($stage->is_default) {
                throw LeadsWorkspaceException::defaultStage();
            }

            $destination = $moveTo ?? $remaining->firstWhere('is_default', true) ?? $remaining->first();

            $moved = Lead::query()
                ->where('team_id', $stage->team_id)
                ->where('pipeline_stage_id', $stage->getKey())
                ->update(['pipeline_stage_id' => $destination->getKey()]);

            $this->audit->log('leads.stage_deleted', $stage, [
                'slug' => $stage->slug,
                'moved_leads' => $moved,
                'moved_to' => $destination->slug,
            ], $actor);

            $stage->delete();
        });
    }
}
