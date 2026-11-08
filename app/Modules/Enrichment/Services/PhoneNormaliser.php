<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Enrichment\Enums\PhoneType;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * A vendor's phone number, turned into something a member can tap (PL3).
 *
 * Backed by libphonenumber rather than by a regex, and that is not gold-plating: a national number
 * is not a country code glued to the digits. `020 7946 0000` is `+442079460000` and the leading
 * zero disappears; `07700 900123` is `+447700900123` and it does too; `050 123 4567` in the UAE is
 * `+971501234567`. Getting any of that wrong does not produce an invalid number — it produces a
 * **valid number belonging to somebody else**, which is the one failure here that reaches a
 * stranger.
 *
 * Two deliberate behaviours:
 *
 * - **It refuses rather than guesses.** A number it cannot parse against the lead's country leaves
 *   `phone_e164` null and the raw value on `phone`, so the screen shows what the vendor said and
 *   offers no tap-to-call. A half-normalised number is worse than an unformatted one.
 * - **It classifies, and a classification it is unsure of is {@see PhoneType::Unknown}** — which
 *   the product reads as a person's number, the cautious direction. A vendor's own `phone_type` is
 *   preferred where it gives one; this is the fallback for the ones that do not.
 *
 * E.164 is the storage and dialling form. It is **not** the matching form — `phone_hash` is bare
 * digits via `ContactIdentity`, so a number written four ways still matches one suppression entry.
 */
final class PhoneNormaliser
{
    public function __construct(private readonly PhoneNumberUtil $phones) {}

    /**
     * The number in E.164 (`+447700900123`), or null when it cannot be read with confidence.
     *
     * `$country` is the lead's ISO-3166 alpha-2 where we know it. A number already in international
     * form parses without one; a national number without a country is unparseable, and inventing a
     * default would be inventing a country code.
     */
    public function toE164(string $number, ?string $country = null): ?string
    {
        $parsed = $this->parse($number, $country);

        if ($parsed === null) {
            return null;
        }

        // `isValidNumber`, not merely `isPossibleNumber`: possible means "the right length", and
        // the right length is exactly how a mistyped number becomes somebody else's.
        if (! $this->phones->isValidNumber($parsed)) {
            return null;
        }

        return $this->phones->format($parsed, PhoneNumberFormat::E164);
    }

    /**
     * What kind of number this is, as far as the library can tell.
     *
     * Mobile and fixed-line are the two that matter commercially: a mobile reaches a person and a
     * landline reaches a building. A fixed line is read as a {@see PhoneType::Switchboard} — the
     * cautious *commercial* reading, since treating a company's main line as somebody's direct dial
     * would mask it and charge to reveal a number printed on the homepage.
     *
     * Note which way each default falls: unknown **kind** is a person's (compliance-cautious),
     * while a known fixed line is a company's (cost-cautious). Both are deliberate.
     */
    public function classify(string $number, ?string $country = null): PhoneType
    {
        $parsed = $this->parse($number, $country);

        if ($parsed === null) {
            return PhoneType::Unknown;
        }

        return match ($this->phones->getNumberType($parsed)) {
            PhoneNumberType::MOBILE => PhoneType::Mobile,
            // A number that could be either is read as a mobile: it is the costly-to-get-wrong
            // direction for compliance, because a mobile is personal data and a switchboard is not.
            PhoneNumberType::FIXED_LINE_OR_MOBILE => PhoneType::Mobile,
            PhoneNumberType::FIXED_LINE => PhoneType::Switchboard,
            default => PhoneType::Unknown,
        };
    }

    /**
     * Whether a number can be read at all — used to refuse a vendor's answer before storing it.
     */
    public function isValid(string $number, ?string $country = null): bool
    {
        return $this->toE164($number, $country) !== null;
    }

    private function parse(string $number, ?string $country): ?PhoneNumber
    {
        $number = trim($number);

        if ($number === '') {
            return null;
        }

        try {
            // The region is only consulted for a number that is not already international, so
            // passing a wrong one cannot corrupt a `+`-prefixed value.
            return $this->phones->parse($number, $country === null ? null : strtoupper($country));
        } catch (NumberParseException) {
            return null;
        }
    }
}
