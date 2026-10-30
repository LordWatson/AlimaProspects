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

    /**
     * Claimed for sending, outcome not yet known.
     *
     * The row is moved here by a **conditional update** before the provider is called, and that
     * claim is what makes a double-send impossible: two simultaneous Send requests both read
     * `draft`, but only one of them wins the `where status = draft` update, and the loser is
     * refused before it reaches the mailer. Checking a status read into memory a moment earlier
     * cannot do that.
     *
     * A row left here means the send went out and the bookkeeping afterwards did not finish. It is
     * deliberately **not** re-sendable: a missing attempt row is recoverable from the log and the
     * provider's own dashboard, whereas a second email to a prospect is not recoverable at all.
     */
    case Sending = 'sending';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Logged => 'Logged',
            self::Failed => 'Failed',
            self::Sending => 'Sending',
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
            // `Sending` is honestly unknown: the provider may have taken it. Reporting must not
            // count it as an approach, and the send must not be offered again — which is why
            // "has it gone out" and "may it be sent" are two questions (see `allowsSend()`).
            self::Draft, self::Failed, self::Sending => false,
        };
    }

    /**
     * Whether a send may be attempted from this status.
     *
     * Distinct from {@see self::hasLeftTheBuilding()} on purpose: a **failed** send has not
     * reached the lead and should be re-sendable once the team fixes whatever the provider
     * objected to, while a send **in flight** has not reached them either and must not be tried
     * again.
     */
    public function allowsSend(): bool
    {
        return match ($this) {
            self::Draft, self::Failed => true,
            self::Sent, self::Logged, self::Sending => false,
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
