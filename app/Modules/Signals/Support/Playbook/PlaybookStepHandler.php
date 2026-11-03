<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support\Playbook;

use App\Modules\Signals\Contracts\SignalOutreachDrafter;
use App\Modules\Signals\Data\PlaybookStepOutcome;
use App\Modules\Signals\Data\SignalPlaybookStep;
use App\Modules\Signals\Enums\PlaybookActionKind;

/**
 * One kind of playbook step, and the one rule every implementation obeys (S14).
 *
 * **A handler calls the owning module's Action. It never reimplements one.** Assignment is Leads
 * Workspace's `SetLeadAssigneesAction`, a stage move is its `MoveLeadStageAction`, a tag is its
 * `SyncLeadTagsAction`, a task is its `CreateLeadTaskAction`; a conversion is Signals' own
 * `ConvertSignalToLeadAction`; a notification is the dispatcher; a draft is Outreach's drafting
 * path reached through {@see SignalOutreachDrafter}. That is not
 * tidiness — those Actions carry the transaction, the timeline entry, the audit entry, the
 * post-commit event and the tenancy check, and a handler that wrote the row itself would be a
 * second implementation of all five, silently diverging the first time one of them changed.
 *
 * A handler **never throws for an expected outcome.** No lead to act on, a stage that has been
 * deleted, a member who has left, a guard that refused: each is a `PlaybookStepOutcome` with a
 * reason, because the run log is the deliverable and an exception is not a sentence anybody can
 * read. Throwing is reserved for the unexpected, which the executor catches and records as failed.
 *
 * These live in `Support/` per ADR 0002 — small collaborators that are neither an Action (they own
 * no operation of their own) nor a Service (they orchestrate nothing beyond one call).
 */
interface PlaybookStepHandler
{
    public function kind(): PlaybookActionKind;

    public function handle(PlaybookContext $context, SignalPlaybookStep $step): PlaybookStepOutcome;
}
