<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Services;

use App\Models\User;
use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Actions\DraftMessageAction;
use App\Modules\Outreach\Data\DraftMessageData;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachTone;
use App\Modules\Outreach\Exceptions\OutreachBlockedException;
use App\Modules\Signals\Contracts\SignalOutreachDrafter;
use App\Modules\Signals\Data\PlaybookDraftOutcome;

/**
 * Outreach's answer to a playbook asking for an approach to be prepared (S14).
 *
 * It implements **Signals'** contract, which is the direction the dependency has to point: Signals
 * declares what it needs and ships a refusing default, and this module — registered after it —
 * binds the real thing over the top. The same shape Compliance's three seams have.
 *
 * **It drafts. It never sends.** There is no send path on the interface, and this class adds none
 * (docs/compliance.md §3.12). What it does add is one thing the interactive draft screen does not
 * need: the guard is asked **before** {@see DraftMessageAction} rather than left to throw inside it.
 * The reason is the run log. A member who presses Draft and is refused reads a message on the
 * screen; a rule that is refused at three in the morning has only the log row, and the log row has
 * to name the rule that said no and what to do instead. Catching an exception would give us a
 * message to show and not the structured decision — so the decision is taken here, reported, and
 * only then is any AI credit spent.
 *
 * The compliance half only, and deliberately: a configuration refusal — no contact chosen, no
 * verified sender, sending switched off — must not stop a draft being written, because a member can
 * still copy it into their own mailbox. That is {@see OutreachGuard::assertCompliance()}'s whole
 * argument, and a rule gets the same treatment a person does.
 *
 * No contact is addressed. A signal carries a name and a public role and never an email
 * (docs/compliance.md §2), and the conversion writes the contact with no address, so there is
 * nobody to pick. The draft is for the company, which is also why the channel the builder offers
 * first is one Alima does not transmit.
 */
final class PlaybookOutreachDrafter implements SignalOutreachDrafter
{
    public function __construct(
        private readonly OutreachGuard $guard,
        private readonly DraftMessageAction $draft,
    ) {}

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public function channels(): array
    {
        return array_map(static fn (OutreachChannel $channel): array => [
            'value' => $channel->value,
            'label' => $channel->label(),
            'description' => $channel->description(),
        ], OutreachChannel::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function tones(): array
    {
        return array_map(static fn (OutreachTone $tone): array => [
            'value' => $tone->value,
            'label' => $tone->label(),
        ], OutreachTone::cases());
    }

    public function draftForReview(
        Team $team,
        Lead $lead,
        string $channel,
        string $tone,
        User $actor,
    ): PlaybookDraftOutcome {
        $outreachChannel = OutreachChannel::tryFrom($channel);
        $outreachTone = OutreachTone::tryFrom($tone);

        if ($outreachChannel === null || $outreachTone === null) {
            // A rule saved against a channel or register this build no longer offers. Unavailable
            // rather than blocked: nothing about the prospect refused.
            return PlaybookDraftOutcome::unavailable();
        }

        // The compliance half of the gate, before a token is spent. No contact and no member
        // passed: this asks whether the *prospect* may be approached on this channel, which is the
        // only half that is final.
        $decision = $this->guard->decide($team, $lead, $outreachChannel);
        $complianceReason = null;

        foreach ($decision->reasons as $reason) {
            if ($reason->isCompliance()) {
                $complianceReason = $reason;

                break;
            }
        }

        if ($complianceReason !== null) {
            return PlaybookDraftOutcome::blocked(
                reasonKey: $complianceReason->value,
                // Translation keys from Outreach's own enum, passed through rather than restated.
                reasonLabel: $complianceReason->label(),
                reasonGuidance: $complianceReason->guidance(),
                isCompliance: true,
            );
        }

        // The model call and the write. Outside any transaction, which the Action guarantees. A
        // failure here — exhausted credit, nothing usable back — is left to throw: the playbook
        // handler records it as a failure, which is the status that means somebody should look.
        try {
            $message = $this->draft->handle($team, $lead, $actor, new DraftMessageData(
                channel: $outreachChannel,
                tone: $outreachTone,
                // The team's own language, not the actor's: the rule runs unattended, and the draft
                // is for whoever picks it up.
                locale: SupportedLocale::tryFromString($team->locale) ?? SupportedLocale::default(),
            ));
        } catch (OutreachBlockedException $e) {
            // Belt to the braces above: the guard is asked twice on every draft path, and a
            // restriction added between the two checks must land as a refusal rather than a crash.
            $decision = $e->decision();
            $reason = $decision->primaryReason();

            // `OutreachDecision::block()` refuses a reasonless block, so there is always one.
            return PlaybookDraftOutcome::blocked(
                reasonKey: $reason->value,
                reasonLabel: $reason->label(),
                reasonGuidance: $reason->guidance(),
                isCompliance: $decision->isComplianceBlock(),
            );
        }

        return PlaybookDraftOutcome::drafted((int) $message->getKey());
    }
}
