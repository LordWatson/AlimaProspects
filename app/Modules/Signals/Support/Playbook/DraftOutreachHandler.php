<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support\Playbook;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Contracts\SignalOutreachDrafter;
use App\Modules\Signals\Data\PlaybookStepOutcome;
use App\Modules\Signals\Data\SignalPlaybookStep;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookOutcomeReason;

/**
 * "Draft an approach for review" — and the step this phase is most careful about (S14).
 *
 * **A playbook can prepare an approach. It cannot send one** (docs/compliance.md §3.12). The draft
 * is written and kept for a person to read, and that is the whole of it. There is no send on
 * {@see SignalOutreachDrafter} and there will not be one: `OutreachGuard` answers whether a prospect
 * may be approached, suppression and sender identification and the opt-out are conditions of
 * sending, and a UAE-jurisdiction signal carries a prior-consent restriction nothing published can
 * satisfy. An unattended rule that transmitted would be the platform settling a legal question on a
 * team's behalf, overnight, with nobody reading it. This is a recorded decision, not an omission
 * somebody later "fixes".
 *
 * **A refusal is a first-class outcome.** When the guard says this prospect may not be approached,
 * the step records `outreach_blocked` with the guard's *own* reason key, label and guidance, and the
 * run log shows it. Signals does not restate Outreach's vocabulary — which rule refused is
 * Outreach's judgement, and a second copy of it here would be the copy that drifts.
 *
 * The other half of the care is cost. This is the step that spends AI credit, so:
 *
 * - it meets {@see PlaybookBudget}'s own drafting ceiling, separate from the conversion one;
 * - a **rehearsal never calls the model**, because a dry run that spent credit would make the brake
 *   cost money;
 * - and the drafting call is outside every transaction, which the contract states and the Action
 *   guarantees.
 */
final class DraftOutreachHandler implements PlaybookStepHandler
{
    public function __construct(private readonly SignalOutreachDrafter $drafter) {}

    public function kind(): PlaybookActionKind
    {
        return PlaybookActionKind::DraftOutreach;
    }

    public function handle(PlaybookContext $context, SignalPlaybookStep $step): PlaybookStepOutcome
    {
        $lead = $context->lead();

        if (! $lead instanceof Lead) {
            return PlaybookStepOutcome::skipped($this->kind(), PlaybookOutcomeReason::NoLead);
        }

        $channel = $step->channel();
        $tone = $step->tone();

        if ($channel === null || $tone === null) {
            return PlaybookStepOutcome::refused($this->kind(), PlaybookOutcomeReason::DraftingUnavailable);
        }

        if (! $context->performs()) {
            if (! $context->budget->mayDraftInRehearsal()) {
                return PlaybookStepOutcome::refused($this->kind(), PlaybookOutcomeReason::DraftCapReached);
            }

            $context->budget->recordRehearsedDraft();

            // **No model call.** A rehearsal reports the channel it would have used and nothing
            // else — including, deliberately, nothing about whether the guard would have refused.
            // Asking the guard here would be cheap and would also mean the rehearsal's answer went
            // stale the moment somebody opted out, which is the one thing the log must not imply.
            return PlaybookStepOutcome::simulated($this->kind(), ['channel' => $channel]);
        }

        if (! $context->budget->mayDraft()) {
            return PlaybookStepOutcome::refused($this->kind(), PlaybookOutcomeReason::DraftCapReached);
        }

        // **Counted before the call, not after** — the ceiling counts *attempts*. Counting
        // successes left an outage unbounded: a hanging provider meant two hundred signals each
        // waiting out a timeout, with nothing consumed, until the job's own timeout killed the
        // worker — which is the one thing that loses a claimed run's work (found in the S14
        // review). A refusal by the guard is counted for the same reason: a rule refused on every
        // signal should not be free to ask two hundred times.
        $context->budget->recordDraft();

        $outcome = $this->drafter->draftForReview($context->team, $lead, $channel, $tone, $context->actor);

        if ($outcome->blocked) {
            return PlaybookStepOutcome::refused(
                $this->kind(),
                PlaybookOutcomeReason::OutreachBlocked,
                [...$outcome->toParams(), 'channel' => $channel],
            );
        }

        if ($outcome->unavailable) {
            return PlaybookStepOutcome::refused(
                $this->kind(),
                PlaybookOutcomeReason::DraftingUnavailable,
                ['channel' => $channel],
            );
        }

        return PlaybookStepOutcome::applied($this->kind(), [
            'channel' => $channel,
            // So the log can link to the draft a person is being asked to read.
            'message_id' => $outcome->messageId,
        ], $lead);
    }
}
