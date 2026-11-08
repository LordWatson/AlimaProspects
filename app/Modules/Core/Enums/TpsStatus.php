<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

/**
 * What is known about a number's do-not-call registration (PL5) — the value on
 * `lead_contacts.tps_status`.
 *
 * **Four states, and three of them are not "clear".** The one that matters is `Unchecked`: a number
 * nobody has screened is not a number nobody may call, and it is certainly not a cleared one.
 * Collapsing the two — defaulting the column to `clear`, or treating a provider outage as a pass —
 * would turn a missing licence into a green light on every row in the product, which is the way
 * this fails in practice.
 *
 * `NotApplicable` is the other one worth reading: TPS and CTPS are **UK** registers. A Dubai number
 * is not clear of them, it is outside them, and the UAE answer is a different question
 * ({@see Market::Uae}'s prior-consent restriction, already enforced by `OutreachGuard`).
 *
 * **In Core rather than in Compliance**, for the reason {@see OutreachRestriction} is: the column
 * is `lead_contacts.tps_status`, which is Enrichment's table, and Enrichment is registered before
 * Compliance. A module cannot cast a column to an enum from a module that does not exist yet as far
 * as it is concerned. The *rule* stays Compliance's — `TpsScreener` is the only thing that writes
 * one — and only the vocabulary is shared.
 */
enum TpsStatus: string
{
    /** Never screened, or the last verdict has gone stale and not been replaced. */
    case Unchecked = 'unchecked';

    /** Screened and not on the register asked. */
    case Clear = 'clear';

    /** On the register. No unsolicited marketing call, whoever holds the number. */
    case Registered = 'registered';

    /** Not a UK number, so neither register has anything to say about it. */
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Unchecked => 'Not screened',
            self::Clear => 'Not on TPS/CTPS',
            self::Registered => 'Do not cold call — TPS/CTPS',
            self::NotApplicable => 'Outside TPS/CTPS',
        };
    }

    /**
     * Whether the product should offer a one-tap call link for this number.
     *
     * Only a **registered** number loses its dial link. An unscreened one keeps it, which is a
     * deliberate choice and the same one the plan makes about PECR: screening informs the member,
     * it does not make the decision for them, and a product that hid every unscreened number would
     * hide every number until a licence exists. The badge is how they are told.
     */
    public function allowsDialLink(): bool
    {
        return $this !== self::Registered;
    }

    /**
     * Whether this verdict came from a register at all — what decides if a re-screen is due.
     */
    public function isScreened(): bool
    {
        return $this === self::Clear || $this === self::Registered;
    }
}
