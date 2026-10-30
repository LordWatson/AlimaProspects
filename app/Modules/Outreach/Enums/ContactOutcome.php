<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Enums;

use App\Modules\Outreach\Models\ContactAttempt;

/**
 * How a {@see ContactAttempt} went (Phase 11). Set when the attempt is logged and editable
 * afterwards, because the answer usually arrives days later.
 *
 * {@see self::OptedOut} is the one case with a consequence beyond the record: logging it writes the
 * contact to the **global** suppression list immediately (docs/compliance.md §3.3), which is why it
 * is an outcome here rather than a note the member types. An opt-out that depends on someone
 * remembering to also add a suppression entry is an opt-out that will be missed.
 *
 * Values are stable storage keys; {@see self::label()} is a translation key.
 */
enum ContactOutcome: string
{
    /** Logged, nothing back yet. The state most attempts sit in. */
    case Pending = 'pending';

    /** Tried and didn't reach them — no answer, voicemail, no reply in a reasonable window. */
    case NoAnswer = 'no_answer';

    /** They came back to us, without saying yet whether they are interested. */
    case Replied = 'replied';

    case Interested = 'interested';
    case NotInterested = 'not_interested';
    case MeetingBooked = 'meeting_booked';

    /** The address doesn't exist or refused us. Suppresses the address for the team. */
    case Bounced = 'bounced';

    /** They asked not to be contacted. Suppresses the identity globally, for every team. */
    case OptedOut = 'opted_out';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::NoAnswer => 'No answer',
            self::Replied => 'Replied',
            self::Interested => 'Interested',
            self::NotInterested => 'Not interested',
            self::MeetingBooked => 'Meeting booked',
            self::Bounced => 'Bounced',
            self::OptedOut => 'Asked us to stop',
        };
    }

    /**
     * The suppression this outcome demands, or null when it demands none.
     *
     * Branching here rather than at the call site means a new outcome has to answer the question
     * "and does this stop us contacting them?" before it can exist.
     */
    public function suppressionScope(): ?SuppressionScope
    {
        return match ($this) {
            // They asked everyone, not just this team (compliance.md §3.3).
            self::OptedOut => SuppressionScope::Global,
            // A dead address is a fact about the address, and only this team knows it.
            self::Bounced => SuppressionScope::Team,
            self::Pending, self::NoAnswer, self::Replied,
            self::Interested, self::NotInterested, self::MeetingBooked => null,
        };
    }

    public function suppressionReason(): ?SuppressionReason
    {
        return match ($this) {
            self::OptedOut => SuppressionReason::OptOut,
            self::Bounced => SuppressionReason::Bounce,
            default => null,
        };
    }

    /**
     * Whether the outcome is a positive one, for the funnel in Reporting (Phase 13).
     */
    public function isPositive(): bool
    {
        return match ($this) {
            self::Interested, self::MeetingBooked => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $outcome): string => $outcome->value, self::cases());
    }
}
