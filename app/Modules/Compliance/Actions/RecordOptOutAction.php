<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Exceptions\ComplianceException;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadsWorkspace\Actions\RecordLeadActivityAction;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachMessageStatus;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Honours an opt-out the **recipient** made themselves, through the hosted unsubscribe link
 * (docs/compliance.md §3.3). The counterpart to a member recording "Asked us to stop" by hand.
 *
 * Until Phase 15 the footer's only route was `mailto:`, which means a human reading a mailbox and
 * remembering to log it. That is an opt-out honoured by discipline rather than by the system, and
 * it is the gap this closes: RFC 8058 one-click unsubscribe, with the suppression written the
 * moment the recipient asks.
 *
 * Three things it does, in one transaction:
 *
 * 1. **Suppresses the address globally.** An opt-out is a person's wish, not a per-tenant setting
 *    (§3.2), so it binds every team. It suppresses the **phone number too** where one is on the
 *    contact — somebody who unsubscribes from email has not invited a call instead, which is the
 *    same rule the manual route follows.
 * 2. **Records the outcome on the approach**, so the team sees what happened rather than wondering
 *    why a lead went quiet. The attempt's outcome becomes `opted_out` and the timeline entry is
 *    updated in place (one `Contacted` entry per attempt, as Phase 11 established).
 * 3. **Audits it with no actor**, because nobody at the team did this: the recipient did. The entry
 *    carries ids, the masked preview and nothing else.
 *
 * No member is involved and no session exists, so this runs under an explicit {@see TeamContext}.
 */
final class RecordOptOutAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly SuppressionList $suppressions,
        private readonly RecordLeadActivityAction $record,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int how many identities were suppressed — at least one, or an exception
     *
     * @throws ComplianceException when the message has no recorded recipient
     */
    public function handle(OutreachMessage $message): int
    {
        $team = $message->team;

        if (! $team instanceof Team) {
            throw ComplianceException::nothingToSuppress();
        }

        $identities = $this->identities($message);

        if ($identities === []) {
            // A sent message always records what it sent to, so this should be unreachable. It must
            // not silently report success: the recipient would be told they had been removed.
            throw ComplianceException::nothingToSuppress();
        }

        return $this->teamContext->run($team, function () use ($team, $message, $identities): int {
            $suppressed = DB::transaction(function () use ($message, $identities): int {
                $count = 0;

                foreach ($identities as [$type, $value]) {
                    if ($this->suppressions->suppress(
                        type: $type,
                        value: $value,
                        scope: SuppressionScope::Global,
                        reason: SuppressionReason::OptOut,
                        teamId: null,
                        // No actor: the recipient is not a user of the platform.
                        actor: null,
                    ) !== null) {
                        $count++;
                    }
                }

                $this->recordOutcome($message);

                return $count;
            });

            $this->audit->log('compliance.opt_out_recorded', $message, [
                'lead_id' => $message->lead_id,
                'channel' => $message->channel->value,
                'identities' => $suppressed,
                'source' => 'one_click_unsubscribe',
            ]);

            // Counts and ids only — never the address (CLAUDE.md logging rules).
            Log::channel('compliance')->info('One-click unsubscribe honoured.', [
                'team_id' => (int) $team->getKey(),
                'message_id' => (int) $message->getKey(),
                'identities' => $suppressed,
            ]);

            return $suppressed;
        });
    }

    /**
     * Everything we hold for this recipient: the address the message actually went to, and the
     * phone number on the contact it was addressed to, if any.
     *
     * `sent_to` is preferred over the contact's current address because it is what was used at the
     * time — the contact may have been corrected by a later enrichment pass, and the person
     * clicking the link is whoever received *that* message.
     *
     * @return list<array{SuppressionType, string}>
     */
    private function identities(OutreachMessage $message): array
    {
        $identities = [];
        $contact = $message->contact;

        $address = trim((string) ($message->sent_to ?? ($contact instanceof LeadContact ? $contact->email : null) ?? ''));

        if ($address !== '') {
            $identities[] = [SuppressionType::Email, $address];
        }

        $phone = $contact instanceof LeadContact ? trim((string) $contact->phone) : '';

        if ($phone !== '') {
            $identities[] = [SuppressionType::Phone, $phone];
        }

        return $identities;
    }

    /**
     * Mark the approach this message belongs to as opted out, and update its timeline entry.
     *
     * Only an attempt that is still **pending** is changed: a member who has already recorded
     * "they replied" knows something this click does not, and overwriting their answer with a
     * machine's would lose it. The suppression above stands either way, which is the part that
     * matters.
     */
    private function recordOutcome(OutreachMessage $message): void
    {
        $attempts = ContactAttempt::query()
            ->where('outreach_message_id', $message->getKey())
            ->where('outcome', ContactOutcome::Pending->value)
            ->with(['lead', 'message'])
            ->get();

        foreach ($attempts as $attempt) {
            $attempt->forceFill(['outcome' => ContactOutcome::OptedOut])->save();

            $lead = $attempt->lead;

            if ($lead === null) {
                continue;
            }

            $this->record->handle(
                lead: $lead,
                type: LeadActivityType::Contacted,
                subject: $attempt,
                properties: [
                    'channel' => $attempt->channel->value,
                    'outcome' => ContactOutcome::OptedOut->value,
                    'sent' => $attempt->message?->status === OutreachMessageStatus::Sent,
                ],
                actor: null,
                occurredAt: $attempt->occurred_at,
            );
        }
    }
}
