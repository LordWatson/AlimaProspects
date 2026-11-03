<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support\Playbook;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\SyncLeadTagsAction;
use App\Modules\Signals\Data\PlaybookStepOutcome;
use App\Modules\Signals\Data\SignalPlaybookStep;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookOutcomeReason;

/**
 * "Tag the lead" — through Leads Workspace's own {@see SyncLeadTagsAction} (S14).
 *
 * The same shape as the assignment step, and for the same reason: the Action **sets the whole set**,
 * so this adds to the lead's existing labels rather than replacing them. A rule that removed every
 * tag a member had put on a lead would be the single most annoying thing in this phase.
 *
 * Tags are created on first use by the Action, which is what makes a rule saying "tag it
 * Acquisition" work without anybody setting that tag up first. The Action also normalises names,
 * so "Warm", "warm" and " warm " are one label and a rule cannot fill somebody's tag list with
 * near-duplicates.
 */
final class AddTagHandler implements PlaybookStepHandler
{
    public function __construct(private readonly SyncLeadTagsAction $sync) {}

    public function kind(): PlaybookActionKind
    {
        return PlaybookActionKind::AddTag;
    }

    public function handle(PlaybookContext $context, SignalPlaybookStep $step): PlaybookStepOutcome
    {
        $lead = $context->lead();

        if (! $lead instanceof Lead) {
            return PlaybookStepOutcome::skipped($this->kind(), PlaybookOutcomeReason::NoLead);
        }

        $tags = $step->tags();

        if ($tags === []) {
            return PlaybookStepOutcome::skipped($this->kind(), PlaybookOutcomeReason::NoTags);
        }

        if (! $context->performs()) {
            return PlaybookStepOutcome::simulated($this->kind(), ['tags' => $tags]);
        }

        /** @var list<string> $existing */
        $existing = $lead->tags()->pluck('name')->map(static fn (mixed $name): string => (string) $name)->all();

        $wanted = array_values(array_unique([...$existing, ...$tags]));

        // **The workspace's own ceiling, reported rather than swallowed** (found in the S14
        // review). `SyncLeadTagsAction` caps a lead at twenty labels and keeps the ones already on
        // it, so a lead at the ceiling silently drops the rule's label — and recording the step as
        // done would be the log telling a lie about the one thing it exists to record.
        if (count($wanted) > SyncLeadTagsAction::MAX_PER_LEAD) {
            return PlaybookStepOutcome::refused(
                $this->kind(),
                PlaybookOutcomeReason::TagLimitReached,
                ['tags' => $tags],
            );
        }

        $this->sync->handle($context->team, $lead, $wanted, $context->actor);

        // The rule's own labels, not the resulting set: a log row should say what this step did,
        // and the lead's full tag list is on the lead.
        return PlaybookStepOutcome::applied($this->kind(), ['tags' => $tags], $lead);
    }
}
