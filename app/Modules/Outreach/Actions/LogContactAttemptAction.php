<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\RecordLeadActivityAction;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\Outreach\Data\LogAttemptData;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachMessageStatus;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records that the team approached a lead (Phase 11) — the LinkedIn message they pasted, the call
 * they made, or an email Alima sent a moment ago.
 *
 * Two things happen here besides the row:
 *
 * - **An opt-out suppresses the identity immediately**, globally, inside the same transaction
 *   (docs/compliance.md §3.3). Not a job, not a listener, not "the next sweep": the member is
 *   telling us the person asked to be left alone, and the only acceptable gap between hearing that
 *   and honouring it is none. A bounce suppresses the address for the team alone. Which of the two
 *   — or neither — is {@see ContactOutcome::suppressionScope()}'s answer, so a new outcome has to
 *   decide before it can exist.
 * - **The lead's timeline gets an entry**, subjected to the attempt, so recording the outcome days
 *   later updates that entry rather than stacking a second "contacted" on top.
 *
 * Must be called inside the lead's team context.
 */
final class LogContactAttemptAction
{
    public function __construct(
        private readonly RecordLeadActivityAction $record,
        private readonly SuppressionList $suppressions,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Team $team, Lead $lead, User $member, LogAttemptData $data): ContactAttempt
    {
        $contact = $this->contact($lead, $data->leadContactId);
        $message = $this->message($lead, $data->messageId);

        return DB::transaction(function () use ($team, $lead, $member, $data, $contact, $message): ContactAttempt {
            $attempt = ContactAttempt::query()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'lead_contact_id' => $contact?->getKey(),
                'outreach_message_id' => $message?->getKey(),
                'created_by' => $member->getKey(),
                'channel' => $data->channel,
                'outcome' => $data->outcome,
                'occurred_at' => $data->occurredAt,
                'notes' => $data->notes,
            ]);

            // The draft has now been used. Only a draft is promoted: an email already marked Sent
            // keeps that status, because Alima transmitting it is a stronger statement than the
            // member saying so, and a follow-up call logged against the same draft must not
            // downgrade it.
            if ($message instanceof OutreachMessage && $message->status === OutreachMessageStatus::Draft) {
                $message->forceFill(['status' => OutreachMessageStatus::Logged])->save();
            }

            $this->suppressFromOutcome($team, $contact, $member, $data->outcome);

            $this->record->handle(
                lead: $lead,
                type: LeadActivityType::Contacted,
                subject: $attempt,
                properties: [
                    'channel' => $data->channel->value,
                    'outcome' => $data->outcome->value,
                    // Whether Alima transmitted it or the member did it themselves — the timeline
                    // should not imply the platform sent a call.
                    'sent' => $message?->status === OutreachMessageStatus::Sent,
                ],
                actor: $member,
                occurredAt: $data->occurredAt,
            );

            // Ids, enums and counts — never the note, which is free text a member may have put a
            // person's details in (CLAUDE.md logging rules).
            $this->audit->log('outreach.attempt_logged', $attempt, [
                'lead_id' => $lead->getKey(),
                'channel' => $data->channel->value,
                'outcome' => $data->outcome->value,
                'has_notes' => $data->notes !== null,
            ], $member);

            return $attempt;
        });
    }

    /**
     * Change the outcome of an attempt already logged — the usual case, since the answer arrives
     * days after the approach. Re-runs the suppression branch, because "they asked us to stop" is
     * most often set on an existing row rather than a new one.
     */
    public function updateOutcome(
        Team $team,
        ContactAttempt $attempt,
        User $member,
        ContactOutcome $outcome,
        ?string $notes = null,
    ): ContactAttempt {
        return DB::transaction(function () use ($team, $attempt, $member, $outcome, $notes): ContactAttempt {
            $attempt->forceFill([
                'outcome' => $outcome,
                // Null leaves the note alone; a member changing an outcome from a dropdown has not
                // asked to erase what they wrote at the time.
                ...($notes === null ? [] : ['notes' => $notes]),
            ])->save();

            $this->suppressFromOutcome($team, $attempt->contact, $member, $outcome);

            $lead = $attempt->lead;

            if ($lead !== null) {
                $this->record->handle(
                    lead: $lead,
                    type: LeadActivityType::Contacted,
                    subject: $attempt,
                    properties: [
                        'channel' => $attempt->channel->value,
                        'outcome' => $outcome->value,
                        'sent' => $attempt->message?->status === OutreachMessageStatus::Sent,
                    ],
                    actor: $member,
                    occurredAt: $attempt->occurred_at,
                );
            }

            $this->audit->log('outreach.outcome_recorded', $attempt, [
                'lead_id' => $attempt->lead_id,
                'channel' => $attempt->channel->value,
                'outcome' => $outcome->value,
            ], $member);

            return $attempt;
        });
    }

    /**
     * Honour whatever the outcome demands of the do-not-contact list.
     *
     * Suppresses the **email and the phone number** we hold for the contact, not just the channel
     * the attempt was on: somebody who says "stop contacting me" on a call has not given permission
     * to email them instead.
     */
    private function suppressFromOutcome(
        Team $team,
        ?LeadContact $contact,
        User $member,
        ContactOutcome $outcome,
    ): void {
        $scope = $outcome->suppressionScope();
        $reason = $outcome->suppressionReason();

        if ($scope === null || $reason === null) {
            return;
        }

        if (! $contact instanceof LeadContact) {
            // Nothing identified to suppress — an opt-out logged against the company with no
            // contact chosen. Worth a line in the log, because it is the one case where the
            // member's intent cannot be carried out and they should be told to pick the contact.
            Log::channel('outreach')->warning('An outcome asked for suppression but no contact was identified.', [
                'team_id' => $team->getKey(),
                'outcome' => $outcome->value,
            ]);

            return;
        }

        $teamId = (int) $team->getKey();

        foreach ($this->identities($contact, $outcome) as [$type, $value]) {
            $this->suppressions->suppress(
                type: $type,
                value: $value,
                scope: $scope,
                reason: $reason,
                teamId: $teamId,
                actor: $member,
            );
        }
    }

    /**
     * The identities this outcome should suppress.
     *
     * An **opt-out** covers everything we hold for the person: somebody who says "stop contacting
     * me" on a call has not given permission to email them instead. A **bounce** is a fact about
     * one address, so it takes the address and leaves the phone number alone.
     *
     * @return list<array{SuppressionType, string}>
     */
    private function identities(LeadContact $contact, ContactOutcome $outcome): array
    {
        $identities = [];

        $email = trim((string) $contact->email);

        if ($email !== '') {
            $identities[] = [SuppressionType::Email, $email];
        }

        if ($outcome === ContactOutcome::Bounced) {
            return $identities;
        }

        $phone = trim((string) $contact->phone);

        if ($phone !== '') {
            $identities[] = [SuppressionType::Phone, $phone];
        }

        return $identities;
    }

    private function contact(Lead $lead, ?int $contactId): ?LeadContact
    {
        if ($contactId === null) {
            return null;
        }

        return LeadContact::query()->where('lead_id', $lead->getKey())->find($contactId);
    }

    private function message(Lead $lead, ?int $messageId): ?OutreachMessage
    {
        if ($messageId === null) {
            return null;
        }

        return OutreachMessage::query()->where('lead_id', $lead->getKey())->find($messageId);
    }
}
