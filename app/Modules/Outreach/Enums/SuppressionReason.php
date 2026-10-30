<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Enums;

/**
 * Why an identity is on the do-not-contact list. Recorded because the reason governs who may remove
 * the entry and how it must be treated — an opt-out is the person's own instruction, a bounce is a
 * technical fact — and because the audit trail has to be able to say which it was
 * (docs/compliance.md §3.8).
 */
enum SuppressionReason: string
{
    /** They told us to stop. Written globally, and not removable from the product. */
    case OptOut = 'opt_out';

    /** The address rejected us. */
    case Bounce = 'bounce';

    /** A spam complaint. Treated as an opt-out in substance. */
    case Complaint = 'complaint';

    /** The team added it themselves — a client, a competitor, a company they handle offline. */
    case Manual = 'manual';

    /**
     * They exercised their right to erasure (docs/compliance.md §3.5) and the team honoured it.
     *
     * The entry is what makes the erasure **stick**: without it the next discovery run re-sources
     * the same company and re-derives the same address, and the person who asked to be forgotten
     * hears from us again. Keeping a minimal suppression record — a hash and a mask, never the
     * address — is the recognised way to respect an erasure rather than a contradiction of it.
     */
    case Erasure = 'erasure';

    public function label(): string
    {
        return match ($this) {
            self::OptOut => 'Asked us to stop',
            self::Bounce => 'Address bounced',
            self::Complaint => 'Marked us as spam',
            self::Manual => 'Added by the team',
            self::Erasure => 'Asked us to erase their data',
        };
    }

    /**
     * Whether a team may lift an entry recorded for this reason.
     *
     * Scope is the first answer — nobody un-suppresses a global entry — and this is the second,
     * because scope alone is not enough: an **erasure** is recorded on the team's own list (the
     * request was made to that controller) and must still be unliftable, or the safeguard that
     * makes the erasure stick could be removed with a button. A bounce is a technical fact and a
     * manual entry is housekeeping; both are the team's to change.
     */
    public function isLiftable(): bool
    {
        return match ($this) {
            self::Bounce, self::Manual => true,
            self::OptOut, self::Complaint, self::Erasure => false,
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
