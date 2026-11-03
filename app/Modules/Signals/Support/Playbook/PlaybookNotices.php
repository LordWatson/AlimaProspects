<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support\Playbook;

/**
 * The notify steps a job has collected, waiting to go out as one message each (S14).
 *
 * **This class exists because of one rule in `CLAUDE.md` §3:** a notification that would fan out
 * per record is aggregated first. A playbook runs over every signal a watch brought back, so a
 * notify step on a rule that matched twelve of them is twelve notifications to the same person
 * about the same morning — which is a feature somebody switches off inside a week, the exact
 * failure `AnnounceAccountSignals` was shaped to avoid.
 *
 * So handlers do not notify. They record an intent here, keyed by `(recipient, playbook)`, and the
 * job dispatches one aggregated notification per key after every signal has been through the rules.
 * Keyed by playbook as well as by recipient because two rules firing is two different things
 * having happened, and collapsing them would leave somebody unable to tell which rule to go and
 * look at.
 *
 * The payload is counts and ids — a recipient, a rule's name, how many signals, one lead to land
 * on. Never a formed sentence (the notifications module's own rule).
 */
final class PlaybookNotices
{
    /**
     * recipient id => playbook id => [playbook name, signal count, one lead id]
     *
     * @var array<int, array<int, array{playbook: string, signals: int, lead_id: int|null}>>
     */
    private array $notices = [];

    /**
     * Record that this member should hear that this rule fired on one more signal.
     *
     * `$leadId` is remembered only the first time, which is deliberate: one notification carrying
     * one lead to open is more useful than one carrying none, and a list of twelve is a screen the
     * notification is not. The count is what tells them there is more to see.
     */
    public function add(int $recipientId, int $playbookId, string $playbookName, ?int $leadId): void
    {
        $existing = $this->notices[$recipientId][$playbookId] ?? null;

        if ($existing === null) {
            $this->notices[$recipientId][$playbookId] = [
                'playbook' => $playbookName,
                'signals' => 1,
                'lead_id' => $leadId,
            ];

            return;
        }

        $existing['signals']++;
        $existing['lead_id'] ??= $leadId;

        $this->notices[$recipientId][$playbookId] = $existing;
    }

    public function isEmpty(): bool
    {
        return $this->notices === [];
    }

    /**
     * Every recipient's notices, keyed by user id.
     *
     * @return array<int, array<int, array{playbook: string, signals: int, lead_id: int|null}>>
     */
    public function all(): array
    {
        return $this->notices;
    }
}
