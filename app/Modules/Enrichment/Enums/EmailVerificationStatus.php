<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Enums;

/**
 * The outcome of the free-tier email check (Phase 8): syntax → disposable-domain → MX/DNS. The MVP
 * never performs an SMTP probe, so it can confirm an address is *routable* ({@see self::MxFound})
 * but never that it is deliverable — {@see self::confidence()} is a soft 0–100 signal for Scoring,
 * never a hard claim (ADR 0008). Ordered weakest → strongest outcome.
 *
 * Values are stable storage keys; {@see self::label()} is the display name. No magic strings.
 */
enum EmailVerificationStatus: string
{
    /** Not checked, or the address was absent. */
    case Unknown = 'unknown';

    /** Failed a basic RFC syntax check — almost certainly unusable. */
    case InvalidSyntax = 'invalid_syntax';

    /** The domain is a known throwaway / disposable-mail provider. */
    case DisposableDomain = 'disposable_domain';

    /** Valid syntax but the domain publishes no MX (and no A) record — mail can't be routed. */
    case NoMxRecord = 'no_mx_record';

    /** Valid syntax and the domain accepts mail (MX present) — routable, not proven deliverable. */
    case MxFound = 'mx_found';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::InvalidSyntax => 'Invalid syntax',
            self::DisposableDomain => 'Disposable domain',
            self::NoMxRecord => 'No mail server',
            self::MxFound => 'Mail server found',
        };
    }

    /**
     * A soft 0–100 confidence that the address is usable. Deliberately capped below certainty:
     * MX presence proves routability, not that the mailbox exists.
     */
    public function confidence(): int
    {
        return match ($this) {
            self::Unknown => 0,
            self::InvalidSyntax => 0,
            self::DisposableDomain => 10,
            self::NoMxRecord => 20,
            self::MxFound => 60,
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
