<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Services;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Contracts\LeadRestrictionResolver;
use App\Modules\Outreach\Contracts\TeamSendingGate;
use App\Modules\Outreach\Data\OutreachDecision;
use App\Modules\Outreach\Enums\OutreachBlockReason;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Exceptions\OutreachBlockedException;

/**
 * The one gate every draft and every send passes through (Phase 11, docs/compliance.md §3.2–3.3).
 *
 * It answers one question — may this team approach this lead on this channel — and it is asked
 * **twice**: once when the draft screen renders, so a member is never offered a channel they can't
 * use, and again immediately before the message is transmitted, because minutes or days may pass in
 * between and a reply saying "stop" may have arrived in the meantime. The second check is the one
 * that counts; the first is courtesy.
 *
 * What it enforces, in order:
 *
 * 1. **The lead's own restriction**, from {@see LeadRestrictionResolver} and branched on
 *    `allowsDirectMarketing()` rather than on the case — so a restriction added in a later market
 *    can't be missed by a `match` that still compiles (see docs/modules/outreach.md). This blocks
 *    drafting as well as sending: the restriction is the basis on which the lead was allowed to
 *    exist, not a warning to click past.
 *
 *    The resolver is asked rather than `leads.raw.outreach_restriction` read directly, because
 *    *which* restriction applies is a compliance judgement: the recorded flag is only set by the
 *    module that created the lead, so Lead Sourcing's UAE leads carry none. Compliance (Phase 15)
 *    binds a market-aware resolver over Outreach's own; either way the answer can only get
 *    stricter than the flag, never looser.
 * 2. **Whether this team may send at all** — the deployment switch, and the per-team
 *    {@see TeamSendingGate}, which is how a suspended tenant stops reaching prospects without
 *    anybody claiming the prospect is the problem (Phase 19a).
 * 3. **Suppression.** The address and its domain, against the global list and the team's own.
 * 4. **Something to send to**, and **someone to send it from** — the configuration checks, which
 *    only apply to a channel Alima actually transmits.
 *
 * Non-sendable channels (LinkedIn text, a call script) skip 2 to 4 entirely. They are the member's
 * own act on a platform where the other person chooses whether to answer; Alima transmits nothing,
 * has no envelope to identify and cannot enforce an opt-out it never carries. Suppressing them
 * would also leave a restricted lead with no permitted channel at all, which is the opposite of
 * what the restriction asks for.
 */
final class OutreachGuard
{
    public function __construct(
        private readonly SuppressionList $suppressions,
        private readonly SenderIdentityResolver $senderIdentity,
        private readonly LeadRestrictionResolver $restrictions,
        private readonly TeamSendingGate $teamSending,
    ) {}

    /**
     * May this channel be used for this lead?
     *
     * @param  LeadContact|null  $contact  The person being addressed. For a sendable channel, the
     *                                     check is against *this* contact's address — passing none
     *                                     means "is the channel open in principle", which is what
     *                                     the screen asks before a contact has been picked.
     */
    public function decide(
        Team $team,
        Lead $lead,
        OutreachChannel $channel,
        ?LeadContact $contact = null,
        ?User $member = null,
    ): OutreachDecision {
        $reasons = [];

        // 1. The lead's own restriction. Applies to every direct-marketing channel, drafting
        //    included.
        $restriction = $this->restrictions->restrictionFor($team, $lead);

        if ($channel->isDirectMarketing() && $restriction !== null && ! $restriction->allowsDirectMarketing()) {
            $reasons[] = OutreachBlockReason::Restricted;
        }

        if (! $channel->isSendable()) {
            // Nothing else applies: Alima isn't transmitting anything. Note the restriction is
            // still honoured above for a direct-marketing channel that isn't sendable (SMS, when it
            // arrives), which is why the two properties are separate.
            return $reasons === []
                ? OutreachDecision::allow($channel)
                : OutreachDecision::block($channel, $reasons);
        }

        // 2. Is sending switched on at all for this deployment?
        if (! (bool) config('outreach.sending_enabled', true)) {
            $reasons[] = OutreachBlockReason::SendingDisabled;
        }

        // 2a. ...and is it switched on for *this team*? Asked here rather than in middleware
        //     because a queued send and a scheduled one have no request behind them (ADR 0012's
        //     argument). The gate ships permissive; Admin binds a suspension-aware one (Phase 19a).
        if (! $this->teamSending->mayTeamSend($team)) {
            $reasons[] = OutreachBlockReason::TeamSuspended;
        }

        // 3. Somewhere to send it.
        $address = $this->addressFor($contact);

        if ($address === null) {
            $reasons[] = OutreachBlockReason::NoContactPoint;
        } elseif ($this->suppressions->isEmailSuppressed($address, (int) $team->getKey())) {
            $reasons[] = OutreachBlockReason::Suppressed;
        }

        // The lead's own website domain, suppressed as "stop contacting anyone at this company".
        // Checked even when no contact has been chosen yet, so the screen can say so before the
        // member picks one and writes a message they can't send.
        $domain = $lead->domain();

        if (
            $domain !== null
            && ! in_array(OutreachBlockReason::Suppressed, $reasons, strict: true)
            && $this->suppressions->isDomainSuppressed($domain, (int) $team->getKey())
        ) {
            $reasons[] = OutreachBlockReason::Suppressed;
        }

        // 4. Someone to send it from. Only checked when a member is in hand — the screen asks about
        //    the channel in the abstract, the send asks about this member.
        if ($member instanceof User && $this->senderIdentity->resolve($team, $member) === null) {
            $reasons[] = OutreachBlockReason::NoSenderIdentity;
        }

        return $reasons === []
            ? OutreachDecision::allow($channel)
            : OutreachDecision::block($channel, $reasons);
    }

    /**
     * A decision per channel, for the draft screen's channel picker.
     *
     * @return array<string, OutreachDecision> Keyed by channel value.
     */
    public function decideAll(Team $team, Lead $lead, ?LeadContact $contact = null, ?User $member = null): array
    {
        $decisions = [];

        foreach (OutreachChannel::cases() as $channel) {
            $decisions[$channel->value] = $this->decide($team, $lead, $channel, $contact, $member);
        }

        return $decisions;
    }

    /**
     * Throwing version, for the moment of transmission. Callers that are about to do something
     * irreversible use this rather than branching on a decision themselves.
     *
     * @throws OutreachBlockedException
     */
    public function assert(
        Team $team,
        Lead $lead,
        OutreachChannel $channel,
        ?LeadContact $contact = null,
        ?User $member = null,
    ): OutreachDecision {
        $decision = $this->decide($team, $lead, $channel, $contact, $member);

        if (! $decision->allowed) {
            throw OutreachBlockedException::because($decision);
        }

        return $decision;
    }

    /**
     * The **compliance** half of the gate, for drafting. Throws when this lead may not be
     * approached on this channel at all; says nothing about whether the team is set up to send.
     *
     * The split is the whole reason {@see OutreachBlockReason::isCompliance()} exists. Drafting is
     * a private act that reaches nobody, so the two kinds of refusal belong at different moments:
     *
     * - A **compliance** refusal — a prior-consent restriction, an opt-out — is final, and must
     *   land at draft time. Letting a member write a message they can never send and only telling
     *   them at the end spends AI credit on nothing and teaches them that the warning is
     *   negotiable.
     * - A **configuration** refusal — no contact chosen yet, no verified sender, sending switched
     *   off — must *not* block drafting. A member who hasn't picked the recipient, or whose team
     *   hasn't finished its business profile, can still legitimately write the message and copy it
     *   into their own mailbox. Refusing them a draft would be the platform withholding the work
     *   over its own setup.
     *
     * @throws OutreachBlockedException
     */
    public function assertCompliance(
        Team $team,
        Lead $lead,
        OutreachChannel $channel,
        ?LeadContact $contact = null,
    ): OutreachDecision {
        // No member passed, deliberately: the sender identity is a configuration question and this
        // method does not ask it.
        $decision = $this->decide($team, $lead, $channel, $contact);

        $complianceReasons = array_values(array_filter(
            $decision->reasons,
            static fn (OutreachBlockReason $reason): bool => $reason->isCompliance(),
        ));

        if ($complianceReasons !== []) {
            throw OutreachBlockedException::because(OutreachDecision::block($channel, $complianceReasons));
        }

        return OutreachDecision::allow($channel);
    }

    /**
     * The address a message to this contact would go to, or null when there isn't a usable one.
     * A contact whose email is a personal-looking one is **not** refused here — the platform
     * prefers role-based data and flags the rest (compliance.md §2), and the team is the controller
     * for the choice. The flag is surfaced on the screen so the member makes it knowingly.
     */
    private function addressFor(?LeadContact $contact): ?string
    {
        if (! $contact instanceof LeadContact) {
            return null;
        }

        $email = trim((string) $contact->email);

        return $email === '' ? null : $email;
    }
}
