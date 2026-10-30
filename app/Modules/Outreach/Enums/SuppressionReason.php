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

    public function label(): string
    {
        return match ($this) {
            self::OptOut => 'Asked us to stop',
            self::Bounce => 'Address bounced',
            self::Complaint => 'Marked us as spam',
            self::Manual => 'Added by the team',
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
