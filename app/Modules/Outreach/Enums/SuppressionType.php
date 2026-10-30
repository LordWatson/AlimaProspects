<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Enums;

use Illuminate\Support\Str;

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
            self::Phone => '***'.Str::substr($normalised, -4),
        };
    }

    private function normaliseEmail(string $value): ?string
    {
        $value = Str::lower($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
    }

    private function normaliseDomain(string $value): ?string
    {
        $value = Str::lower($value);

        if (Str::contains($value, '@')) {
            // A whole address where a domain was meant: take the domain rather than refuse.
            $value = (string) Str::afterLast($value, '@');
        }

        if (Str::contains($value, '//')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        } else {
            // "acme.com/contact" — with no scheme, parse_url reads the whole thing as a path.
            $value = (string) Str::before($value, '/');
        }

        $value = Str::replaceStart('www.', '', trim($value));

        // A bare label ("acme") isn't a domain and would suppress nothing; a value with whitespace
        // or an `@` still in it is a paste that went wrong.
        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $value) === 1
            ? $value
            : null;
    }

    private function normalisePhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        $digits = Str::replaceStart('00', '', $digits);

        // Short enough to be a typo rather than a number. Deliberately permissive otherwise:
        // refusing a real number means a message sent to someone who asked us not to.
        return Str::length($digits) >= 7 ? $digits : null;
    }

    private function previewEmail(string $normalised): string
    {
        $local = (string) Str::before($normalised, '@');
        $domain = (string) Str::after($normalised, '@');

        return Str::substr($local, 0, 1).'***@'.$domain;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
