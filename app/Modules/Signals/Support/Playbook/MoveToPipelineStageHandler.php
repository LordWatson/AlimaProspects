<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support\Playbook;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\MoveLeadStageAction;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Signals\Data\PlaybookStepOutcome;
use App\Modules\Signals\Data\SignalPlaybookStep;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookOutcomeReason;

/**
 * "Move it to a stage" — through Leads Workspace's own {@see MoveLeadStageAction} (S14).
 *
 * The stage is resolved **inside the team's scope**, so an id from another team's board is a
 * `stage_not_found` rather than a cross-tenant move. That is the belt to the global scope's braces,
 * and it is the check the phase plan asks for by name: a rule is a stored id that can outlive the
 * row it names, and the one way this fails badly is a lead landing on somebody else's column.
 *
 * A move to the stage the lead is already in is the Action's own no-op, and it is reported as
 * `applied` rather than skipped — the rule asked for the lead to be in that column, and it is.
 * Distinguishing "moved" from "was already there" would be a distinction the member did not ask
 * for and the timeline already makes.
 */
final class MoveToPipelineStageHandler implements PlaybookStepHandler
{
    public function __construct(private readonly MoveLeadStageAction $move) {}

    public function kind(): PlaybookActionKind
    {
        return PlaybookActionKind::MoveToPipelineStage;
    }

    public function handle(PlaybookContext $context, SignalPlaybookStep $step): PlaybookStepOutcome
    {
        $lead = $context->lead();

        if (! $lead instanceof Lead) {
            return PlaybookStepOutcome::skipped($this->kind(), PlaybookOutcomeReason::NoLead);
        }

        $stageId = $step->stageId();

        if ($stageId === null) {
            return PlaybookStepOutcome::skipped($this->kind(), PlaybookOutcomeReason::StageNotFound);
        }

        /** @var PipelineStage|null $stage */
        $stage = PipelineStage::query()
            ->where('team_id', $context->team->getKey())
            ->find($stageId);

        if (! $stage instanceof PipelineStage) {
            return PlaybookStepOutcome::skipped($this->kind(), PlaybookOutcomeReason::StageNotFound);
        }

        if (! $context->performs()) {
            return PlaybookStepOutcome::simulated($this->kind(), ['stage' => $stage->name]);
        }

        $this->move->handle($lead, $stage, $context->actor);

        // The stage's own name, which is the team's word for it and the one thing the log row needs
        // to stay readable after somebody renames the column.
        return PlaybookStepOutcome::applied($this->kind(), ['stage' => $stage->name], $lead);
    }
}
