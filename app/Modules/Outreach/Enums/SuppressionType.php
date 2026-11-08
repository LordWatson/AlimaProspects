<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Enums;

use App\Modules\Core\Support\ContactIdentity;

/**
 * What a suppression entry identifies (docs/compliance.md §3.2) and, with it, how a candidate value
 * is normalised before it is hashed. Normalisation belongs to the type rather than to the caller
 * because a suppression list that misses `John@Acme.com ` having stored `john@acme.com` is not a
 * suppression list.
 */
enum SuppressionType: string
{
    /** One address. */
    case Email = 'email';

    /** Every address at a domain — "stop contacting anyone at this company". */
    case Domain = 'domain';

    /** One phone number, digits only. */
    case Phone = 'phone';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email address',
            self::Domain => 'Domain',
            self::Phone => 'Phone number',
        };
    }

    /**
     * The canonical form of a value of this type, or null when it isn't one.
     *
     * - Email: trimmed, lower-cased, and required to look like an address.
     * - Domain: lower-cased, with any scheme, path and leading `www.` stripped, so a pasted URL or
     *   a whole address works.
     * - Phone: digits only, so "+44 20 7946 0000" and "00442079460000" are the same number — a
     *   leading `00` international prefix is dropped, matching E.164's `+`.
     */
    public function normalise(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return match ($this) {
            self::Email => $this->normaliseEmail($value),
            self::Domain => $this->normaliseDomain($value),
            self::Phone => $this->normalisePhone($value),
        };
    }

    /**
     * What a member sees of an entry: enough to recognise it, never enough to use it.
     */
    public function preview(string $normalised): string
    {
        return match ($this) {
            // "j***@example.com" — the domain is the company, which is not the private half.
            self::Email => $this->previewEmail($normalised),
            // A domain is a company, not a person. Shown whole.
            self::Domain => $normalised,
            // The last four digits, the way a card is shown.
            self::Phone => ContactIdentity::maskPhone($normalised),
        };
    }

    /**
     * Delegated to {@see ContactIdentity} since PL2, which is where the one definition of "the same
     * address" now lives.
     *
     * Not a tidy-up: `lead_contacts` also hashes an address from PL2 onward, and if the two
     * normalised differently then the same person would hash to one value in the suppression list
     * and another on the contact — and the consequence of that mismatch is not a missed index, it
     * is an email sent to somebody who opted out. The behaviour here is unchanged; the code moved.
     */
    private function normaliseEmail(string $value): ?string
    {
        return ContactIdentity::email($value);
    }

    private function normaliseDomain(string $value): ?string
    {
        return ContactIdentity::domain($value);
    }

    private function normalisePhone(string $value): ?string
    {
        return ContactIdentity::phone($value);
    }

    private function previewEmail(string $normalised): string
    {
        return ContactIdentity::maskEmail($normalised);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
