<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Enums;

/**
 * What a person who is not a user has asked us for (PL5).
 *
 * The application branches on it, which is what makes it an enum (ADR 0018 section 1): two of the
 * four are honoured **automatically** on verification — suppression plus a cross-tenant purge,
 * which needs no judgement and should not wait for an operator — and two go to a queue, because an
 * access or rectification request has to be read by somebody.
 */
enum SubjectRequestType: string
{
    /** "Stop contacting me." Suppressed platform-wide on verification. */
    case OptOut = 'opt_out';

    /** "Tell me what you hold." Queued; an operator answers it. */
    case Access = 'access';

    /** "Delete what you hold." Suppressed and purged across every team on verification. */
    case Erasure = 'erasure';

    /** "What you hold is wrong." Queued; an operator corrects it. */
    case Rectification = 'rectification';

    public function label(): string
    {
        return match ($this) {
            self::OptOut => 'Stop contacting me',
            self::Access => 'Tell me what you hold about me',
            self::Erasure => 'Delete what you hold about me',
            self::Rectification => 'Correct what you hold about me',
        };
    }

    /**
     * Whether verifying this request is enough to act on it without an operator.
     *
     * An opt-out and an erasure are mechanical: the person has told us to stop, the suppression and
     * the purge follow from that, and nothing a human adds would make them more correct — while a
     * delay is itself the harm the request is about. An access or rectification request is the
     * opposite: it has to be read.
     */
    public function isAutomatic(): bool
    {
        return $this === self::OptOut || $this === self::Erasure;
    }

    /**
     * Whether honouring it deletes data, as opposed to only stopping contact.
     */
    public function erasesData(): bool
    {
        return $this === self::Erasure;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
