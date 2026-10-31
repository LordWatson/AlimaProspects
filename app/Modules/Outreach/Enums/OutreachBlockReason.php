<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Enums;

use App\Modules\Outreach\Services\OutreachGuard;

/**
 * Why {@see OutreachGuard} refused a channel for a lead (Phase 11).
 *
 * An enum rather than a boolean plus a sentence, for three reasons: the member needs to be told
 * which rule stopped them and what to do instead; the API has to report it machine-readably; and
 * the audit log must record *which* check fired without storing the address it fired on.
 *
 * Values are stable storage keys; {@see self::label()} and {@see self::guidance()} are translation
 * keys the screens pass through `t()` (EN/AR, ADR 0007).
 */
enum OutreachBlockReason: string
{
    /**
     * The lead carries a legal limit on how it may be approached —
     * `leads.raw.outreach_restriction` (ADR 0007, ADR 0011). Refused at draft time as well as at
     * send time: the restriction is the basis on which the lead was allowed to exist at all, so it
     * is not a warning to click past.
     */
    case Restricted = 'restricted';

    /** The identity is on the do-not-contact list (docs/compliance.md §3.2). */
    case Suppressed = 'suppressed';

    /** Nothing to send to — the lead has no contact point on this channel. */
    case NoContactPoint = 'no_contact_point';

    /** The team has no verified sender, so a message could not say who it is from. */
    case NoSenderIdentity = 'no_sender_identity';

    /** Sending is switched off for this deployment (no mail provider configured). */
    case SendingDisabled = 'sending_disabled';

    /**
     * An operator has suspended the team (Phase 19a). Configuration rather than compliance:
     * nothing about this prospect has changed, and the team keeps reading and drafting. It is
     * also the one refusal the team cannot fix themselves, which is why the guidance says to
     * talk to us rather than suggesting a setting.
     */
    case TeamSuspended = 'team_suspended';

    public function label(): string
    {
        return match ($this) {
            self::Restricted => 'Get consent first',
            self::Suppressed => 'On the do-not-contact list',
            self::NoContactPoint => 'No contact details',
            self::NoSenderIdentity => 'No verified sender',
            self::SendingDisabled => 'Sending is switched off',
            self::TeamSuspended => 'This account is suspended',
        };
    }

    public function guidance(): string
    {
        return match ($this) {
            self::Restricted => 'Approach via LinkedIn or a referral — not cold email.',
            self::Suppressed => 'This address or domain has opted out. Nothing can be sent to it.',
            self::NoContactPoint => 'Enrich the lead or add a contact, then try again.',
            self::NoSenderIdentity => 'Add a verified sender address in your team settings.',
            self::SendingDisabled => 'Copy the message and send it from your own mailbox instead.',
            self::TeamSuspended => 'Get in touch with us and we will put this right.',
        };
    }

    /**
     * Whether the refusal is about compliance rather than configuration. A compliance refusal is
     * final; a configuration one is something the team can go and fix.
     */
    public function isCompliance(): bool
    {
        return match ($this) {
            self::Restricted, self::Suppressed => true,
            self::NoContactPoint, self::NoSenderIdentity, self::SendingDisabled, self::TeamSuspended => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::cases());
    }
}
