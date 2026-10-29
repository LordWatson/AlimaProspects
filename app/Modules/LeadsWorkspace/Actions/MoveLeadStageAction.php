<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Support\Facades\DB;

/**
 * Moves a lead to another column of the board (Phase 10b) — the drag-and-drop on the kanban, and
 * the stage picker on the detail screen.
 *
 * Writes the timeline entry **and** the audit entry in the same transaction as the move, so the
 * history can never disagree with where the lead actually is. A move to the stage the lead is
 * already in is a no-op rather than a duplicate entry: a drag that lands where it started is not
 * something the team did.
 *
 * Must be called inside the lead's team context.
 */
final class MoveLeadStageAction
{
    public function __construct(
        private readonly RecordLeadActivityAction $record,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Lead $lead, PipelineStage $stage, User $actor): Lead
    {
        $from = $this->currentStage($lead);

        // A lead that has never been placed is **in** the default column, so moving it out of that
        // column is a real move even though its `pipeline_stage_id` was null.
        if ((int) ($from?->getKey() ?? 0) === (int) $stage->getKey()) {
            return $lead;
        }

        return DB::transaction(function () use ($lead, $stage, $actor, $from): Lead {
            $lead->forceFill(['pipeline_stage_id' => $stage->getKey()])->save();

            $this->record->handle(
                lead: $lead,
                type: LeadActivityType::StageChanged,
                // No subject: each move is its own event, so the timeline keeps the whole path a
                // lead took rather than only where it ended up.
                properties: [
                    'from' => $from?->name,
                    'to' => $stage->name,
                    'outcome' => $stage->outcome?->value,
                ],
                actor: $actor,
            );

            $this->audit->log('leads.stage_moved', $lead, [
                'from' => $from?->slug,
                'to' => $stage->slug,
            ], $actor);

            return $lead->setRelation('pipelineStage', $stage);
        });
    }

    /**
     * Where the lead is now: its own stage, or the team's default column when it has never been
     * placed. Null only when the team has no board at all, which the callers prevent by ensuring
     * the default stages exist before offering a move.
     */
    private function currentStage(Lead $lead): ?PipelineStage
    {
        if ($lead->pipeline_stage_id !== null) {
            return $lead->pipelineStage;
        }

        return PipelineStage::query()
            ->where('team_id', $lead->team_id)
            ->where('is_default', true)
            ->first();
    }
}
