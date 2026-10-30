<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Data\LogAttemptData;
use App\Modules\Outreach\Data\OutreachDecision;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachBlockReason;
use App\Modules\Outreach\Enums\OutreachMessageStatus;
use App\Modules\Outreach\Exceptions\OutreachBlockedException;
use App\Modules\Outreach\Exceptions\OutreachException;
use App\Modules\Outreach\Mail\OutreachMessageMail;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Services\OutreachGuard;
use App\Modules\Outreach\Services\SenderIdentityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one drafted email and logs the attempt (Phase 11). The only place in the platform that
 * transmits a message to a prospect.
 *
 * The sequence is chosen so that nothing can be sent that shouldn't be, and nothing that was sent
 * can go unrecorded:
 *
 * 1. **Spent-draft check.** A message already sent or logged is refused. A double-tapped Send
 *    button must not email somebody twice, and this is cheaper and more certain than any UI guard.
 * 2. **The guard, again, here, now.** It already ran when the screen rendered, but minutes or days
 *    may have passed and a reply saying "stop" may have arrived in between. This is the check that
 *    counts; the earlier one was courtesy. It asserts the sender identity too, which drafting
 *    deliberately did not.
 * 3. **The send, outside any transaction** (CLAUDE.md: never an external call inside one).
 * 4. **The write, in one transaction** — status, provider id, and a logged
 *    {@see ContactAttempt} — so a sent message always leaves a record. A failure writes
 *    `failed` with the provider's message, leaving the draft re-sendable rather than stranded.
 *
 * Must be called inside the lead's team context.
 */
final class SendOutreachEmailAction
{
    public function __construct(
        private readonly OutreachGuard $guard,
        private readonly SenderIdentityResolver $senderIdentity,
        private readonly LogContactAttemptAction $log,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws OutreachException when the draft is spent, isn't sendable, or the provider refuses
     * @throws OutreachBlockedException when the guard refuses the send
     */
    public function handle(Team $team, OutreachMessage $message, User $member): OutreachMessage
    {
        if ($message->isSpent()) {
            throw OutreachException::alreadyUsed();
        }

        if (! $message->channel->isSendable()) {
            throw OutreachException::notSendable();
        }

        $lead = $message->lead;
        $contact = $message->contact;

        if (! $lead instanceof Lead) {
            // The lead cascades on delete, so this is only reachable mid-deletion. Treated as a
            // block rather than a crash: there is nobody to send to.
            throw OutreachBlockedException::because(
                OutreachDecision::block(
                    $message->channel,
                    [OutreachBlockReason::NoContactPoint],
                ),
            );
        }

        // Re-checked at the moment of sending, with the member in hand so the sender identity is
        // asserted as well.
        $this->guard->assert($team, $lead, $message->channel, $contact, $member);

        $sender = $this->senderIdentity->resolve($team, $member);

        if ($sender === null) {
            // The guard just said otherwise, so this is a belt-and-braces narrowing for the type
            // checker rather than a reachable path. Still a block, never a send without a sender.
            throw OutreachBlockedException::because(
                OutreachDecision::block(
                    $message->channel,
                    [OutreachBlockReason::NoSenderIdentity],
                ),
            );
        }

        $address = $this->addressFor($contact);

        // --- External work, outside any transaction ---
        try {
            $sent = Mail::to($address)->send(new OutreachMessageMail($message, $sender));
            $providerId = $sent?->getMessageId();
        } catch (Throwable $e) {
            return $this->recordFailure($team, $message, $member, $e);
        }

        return DB::transaction(function () use ($team, $lead, $message, $member, $address, $providerId): OutreachMessage {
            $message->forceFill([
                'status' => OutreachMessageStatus::Sent,
                // The address used **at the time**, which is the point of the column: the contact's
                // email may be corrected by a later enrichment pass, and this has to stay the
                // record of where the message actually went.
                'sent_to' => $address,
                'provider_message_id' => $providerId,
                'error_message' => null,
                'sent_at' => now(),
            ])->save();

            // The attempt, the timeline entry and the audit row all come from the one action that
            // owns them, so a sent email looks exactly like a logged call in the lead's history.
            $this->log->handle($team, $lead, $member, new LogAttemptData(
                channel: $message->channel,
                outcome: ContactOutcome::Pending,
                occurredAt: now()->utc()->toDateTimeImmutable(),
                leadContactId: $message->lead_contact_id,
                messageId: (int) $message->getKey(),
            ));

            // Ids and enums only — never the address or the body (CLAUDE.md logging rules).
            $this->audit->log('outreach.email_sent', $message, [
                'lead_id' => $lead->getKey(),
                'lead_contact_id' => $message->lead_contact_id,
                'channel' => $message->channel->value,
                'locale' => $message->locale->value,
            ], $member);

            Log::channel('outreach')->info('Outreach email sent.', [
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'message_id' => $message->getKey(),
                'provider_message_id' => $providerId,
            ]);

            return $message;
        });
    }

    /**
     * Mark the message failed and tell the member. The provider's own message is kept on the row —
     * "domain not verified" is exactly what they need to see, and it is not a secret — but it never
     * reaches the structured log, which gets the exception class instead.
     *
     * @throws OutreachException always
     */
    private function recordFailure(Team $team, OutreachMessage $message, User $member, Throwable $e): never
    {
        $message->forceFill([
            'status' => OutreachMessageStatus::Failed,
            'error_message' => Str::limit($e->getMessage(), 500),
        ])->save();

        Log::channel('outreach')->error('Outreach email failed to send.', [
            'team_id' => $team->getKey(),
            'lead_id' => $message->lead_id,
            'message_id' => $message->getKey(),
            'exception' => $e::class,
        ]);

        $this->audit->log('outreach.email_failed', $message, [
            'lead_id' => $message->lead_id,
            'channel' => $message->channel->value,
        ], $member);

        throw OutreachException::sendFailed();
    }

    /**
     * The guard has already established there is one, so this narrows the type rather than deciding
     * anything.
     */
    private function addressFor(?LeadContact $contact): string
    {
        return trim((string) $contact?->email);
    }
}
