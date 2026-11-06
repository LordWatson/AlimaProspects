<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support\Playbook;

use App\Modules\Core\Enums\TeamRole;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Data\PlaybookStepOutcome;
use App\Modules\Signals\Data\SignalPlaybookStep;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookNotifyAudience;
use App\Modules\Signals\Enums\PlaybookOutcomeReason;

/**
 * "Tell someone" — and the one handler that **sends nothing** (S14).
 *
 * It records an intent on {@see PlaybookNotices}, and the job dispatches one aggregated notification
 * per recipient per rule after every signal has been through the rules. That is not an optimisation:
 * `CLAUDE.md` §3 says a notification that would fan out per record is aggregated first, and a rule
 * that matched twelve of a morning's signals would otherwise be twelve messages to the same person
 * about the same morning — the feature somebody switches off inside a week.
 *
 * {@see PlaybookNotifyAudience::LeadOwners} is the default and usually the right answer, for S10's
 * reason: an event about a company somebody is working is news for *that* person, and telling the
 * whole team makes it everybody's to ignore. It **falls back to the team** when nobody owns the
 * lead, which is deliberate rather than a shrug — an unowned lead with an acquisition against it is
 * exactly the lead somebody should pick up, and saying nothing because it has no owner would be the
 * wrong silence.
 *
 * It needs no lead: a rule that only watches and tells is a perfectly good rule, and the team
 * audience works with nothing converted.
 */
final class NotifyHandler implements PlaybookStepHandler
{
    public function kind(): PlaybookActionKind
    {
        return PlaybookActionKind::Notify;
    }

    public function handle(PlaybookContext $context, SignalPlaybookStep $step): PlaybookStepOutcome
    {
        $audience = $step->notifyAudience();
        $recipients = $this->recipients($context, $step, $audience);

        if ($recipients === []) {
            return PlaybookStepOutcome::skipped($this->kind(), PlaybookOutcomeReason::NoMembers);
        }

        if (! $context->performs()) {
            return PlaybookStepOutcome::simulated($this->kind(), [
                'audience' => $audience->value,
                'recipients' => count($recipients),
            ]);
        }

        $lead = $context->lead();

        foreach ($recipients as $recipientId) {
            $context->notices->add(
                recipientId: $recipientId,
                playbookId: (int) $context->playbook->getKey(),
                playbookName: $context->playbook->name,
                leadId: $lead instanceof Lead ? (int) $lead->getKey() : null,
            );
        }

        return PlaybookStepOutcome::applied($this->kind(), [
            'audience' => $audience->value,
            'recipients' => count($recipients),
        ], $lead);
    }

    /**
     * Who to tell, as member ids of this team.
     *
     * Every branch reads through the team's own membership, so a leaver is never a recipient and a
     * foreign id resolves to nobody. The dispatcher filters again when it sends — a member may
     * leave between this and the job's last step — and that double check is the module's own rule,
     * not a redundancy here.
     *
     * @return list<int>
     */
    private function recipients(
        PlaybookContext $context,
        SignalPlaybookStep $step,
        PlaybookNotifyAudience $audience,
    ): array {
        $lead = $context->lead();

        if ($audience === PlaybookNotifyAudience::LeadOwners && $lead instanceof Lead) {
            /** @var list<int> $owners */
            $owners = $lead->assignments()
                ->pluck('user_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($owners !== []) {
                return $owners;
            }
        }

        $query = $context->team->users();

        if ($audience === PlaybookNotifyAudience::Admins) {
            $query->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value]);
        }

        if ($audience === PlaybookNotifyAudience::Named) {
            $named = $step->memberIds();

            if ($named === []) {
                return [];
            }

            $query->whereIn('users.id', $named);
        }

        /** @var list<int> $ids */
        $ids = $query->pluck('users.id')->map(static fn (mixed $id): int => (int) $id)->all();

        return $ids;
    }
}
