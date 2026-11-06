<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support\Playbook;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\CreateLeadTaskAction;
use App\Modules\LeadsWorkspace\Data\LeadTaskData;
use App\Modules\Signals\Data\PlaybookStepOutcome;
use App\Modules\Signals\Data\SignalPlaybookStep;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookOutcomeReason;
use Illuminate\Support\Facades\Date;

/**
 * "Create a task" — through Leads Workspace's own {@see CreateLeadTaskAction} (S14).
 *
 * The due date is **days from now**, not a date, and that is the only interesting thing here: a
 * playbook is a standing instruction, so a stored date would be in the past by the second week. The
 * conversion to a timestamp happens at the moment the step runs, in UTC, as the column is
 * (CLAUDE.md §2) — the team reads it in their own timezone.
 *
 * `remind_at` is set to the same moment as `due_at` rather than left null. A task a rule created is
 * one nobody chose to create, so it is the one most likely to sit unread; the reminder is what
 * makes it arrive. A member who wanted silence can clear it on the task.
 *
 * It can be assigned to **whoever is working the lead**, which is usually what a rule means and is
 * resolved at run time rather than stored — the owner may well have been set by an assignment step
 * two lines above.
 */
final class CreateTaskHandler implements PlaybookStepHandler
{
    public function __construct(private readonly CreateLeadTaskAction $create) {}

    public function kind(): PlaybookActionKind
    {
        return PlaybookActionKind::CreateTask;
    }

    public function handle(PlaybookContext $context, SignalPlaybookStep $step): PlaybookStepOutcome
    {
        $lead = $context->lead();

        if (! $lead instanceof Lead) {
            return PlaybookStepOutcome::skipped($this->kind(), PlaybookOutcomeReason::NoLead);
        }

        $title = $step->title();

        if ($title === null) {
            return PlaybookStepOutcome::skipped($this->kind(), PlaybookOutcomeReason::NoTitle);
        }

        $assignee = $this->assignee($context, $step);
        $dueInDays = $step->dueInDays();

        if (! $context->performs()) {
            return PlaybookStepOutcome::simulated($this->kind(), [
                'title' => $title,
                'due_in_days' => $dueInDays,
            ]);
        }

        $dueAt = $dueInDays === null
            ? null
            : Date::now()->utc()->addDays($dueInDays)->toDateTimeImmutable();

        $this->create->handle($lead, $context->actor, new LeadTaskData(
            title: $title,
            assignedTo: $assignee,
            dueAt: $dueAt,
            // Same moment as the due date — see the class docblock. Null when there is no due date,
            // because a reminder with nothing to remind about would fire the instant it was made.
            remindAt: $dueAt,
        ));

        return PlaybookStepOutcome::applied($this->kind(), [
            'title' => $title,
            'due_in_days' => $dueInDays,
        ], $lead);
    }

    /**
     * Who the task is for: whoever is working the lead, a named member, or nobody.
     *
     * "Nobody" is a real answer — `CreateLeadTaskAction` says so — and it is what a rule means by
     * naming no one: the team owns it. A named id is checked against the team's membership, so a
     * leaver's task becomes the team's rather than being attributed to somebody who has gone.
     */
    private function assignee(PlaybookContext $context, SignalPlaybookStep $step): ?int
    {
        if ($step->assignToLeadOwner()) {
            $owner = $context->lead()?->assignments()->orderBy('user_id')->value('user_id');

            return $owner === null ? null : (int) $owner;
        }

        $named = $step->memberIds()[0] ?? null;

        if ($named === null) {
            return null;
        }

        $member = $context->team->users()->where('users.id', $named)->value('users.id');

        return $member === null ? null : (int) $member;
    }
}
