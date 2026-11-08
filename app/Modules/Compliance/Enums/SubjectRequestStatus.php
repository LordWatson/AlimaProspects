<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Enums;

/**
 * Where a public data-subject request has got to (PL5).
 *
 * `PendingVerification` is the state that matters. A request sits there holding nothing and doing
 * nothing until the person clicks the link in their own inbox, because an unverified form is a way
 * to erase somebody else's prospects by typing their address into it — and an unverified request
 * must not reach an operator's queue either, or the queue becomes the attack surface the
 * verification was protecting.
 */
enum SubjectRequestStatus: string
{
    /** Submitted. Nothing has happened and nothing will until the link is clicked. */
    case PendingVerification = 'pending_verification';

    /** Verified and being honoured, or waiting for an operator. */
    case Open = 'open';

    /** Done. */
    case Completed = 'completed';

    /** Refused, with a note saying why. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'Awaiting verification',
            self::Open => 'Open',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Completed || $this === self::Rejected;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
