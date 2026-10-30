<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Enums;

use App\Modules\Outreach\Models\OutreachMessage;

/**
 * Where an {@see OutreachMessage} has got to (Phase 11).
 *
 * `Logged` and `Sent` are both "it went out", and kept apart because the difference is who
 * transmitted it: Alima sent the email and holds a provider id for it, whereas a LinkedIn message
 * or a call is the member's own act and Alima has only their word for it. Reporting must not count
 * the second as deliverability data.
 *
 * Values are stable storage keys; {@see self::label()} is a translation key.
 */
enum OutreachMessageStatus: string
{
    /** Written, not used. Costs nothing and tells nobody. */
    case Draft = 'draft';

    /** Alima transmitted it (email). A provider message id is on the row. */
    case Sent = 'sent';

    /** The member used it themselves and said so (LinkedIn, call). */
    case Logged = 'logged';

    /** Alima tried to transmit it and the provider refused or errored. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Logged => 'Logged',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether the lead has actually been approached with this message. The one question the
     * timeline, the pipeline and Reporting all ask.
     */
    public function hasLeftTheBuilding(): bool
    {
        return match ($this) {
            self::Sent, self::Logged => true,
            self::Draft, self::Failed => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
