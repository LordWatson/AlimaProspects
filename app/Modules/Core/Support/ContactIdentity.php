<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Outreach\Enums\SuppressionType;
use Illuminate\Support\Str;

/**
 * The one answer to "is this the same email address, the same phone number, the same domain" — and
 * to "how much of it may a screen show" (PL2).
 *
 * **Why this is in Core rather than in either module that wants it.** Two places need to recognise
 * an identity: `outreach_suppressions`, which stores a hash of everyone who has asked us to leave
 * them alone, and `lead_contacts`, which from PL2 stores a hash of every address a vendor found.
 * If those two normalise differently then `Jane@Acme.com ` hashes to one thing in one table and
 * another in the other, and the consequence is not a mismatched index — it is an email sent to
 * somebody who opted out. One implementation, and a test asserting both reach it.
 *
 * {@see SuppressionType} delegates here, so the suppression list's
 * behaviour is unchanged and remains the definition.
 *
 * Two things it deliberately does **not** do:
 *
 * - **No E.164 formatting.** {@see self::phone()} returns bare digits, because its job is matching
 *   and a match must survive a number written four different ways. The dialable form is a separate,
 *   display-and-call concern (`lead_contacts.phone_e164`), and conflating them would mean a number
 *   stored with a country code never matching the same number stored without one.
 * - **No salt.** The hash is deterministic on purpose: the whole point is to recognise the same
 *   identity arriving from somewhere else later, which a salted hash cannot do.
 */
final class ContactIdentity
{
    /**
     * An email address reduced to its comparable form, or null when it is not one.
     *
     * Lower-cased and validated, and nothing else — the local part is **not** touched. Stripping
     * dots or `+tags` would be a guess about one provider's routing rules applied to every
     * provider, and the addresses it wrongly merged would be two different people.
     */
    public static function email(string $value): ?string
    {
        $value = Str::lower(trim($value));

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
    }

    /**
     * A registrable domain reduced to its comparable form, or null when the value is not one.
     *
     * Takes the host out of a URL, the domain out of a whole address, and drops a leading `www.`.
     * A bare label ("acme") is refused: it is not a domain and would match nothing, and a value
     * with whitespace or a stray `@` left in it is a paste that went wrong.
     */
    public static function domain(string $value): ?string
    {
        $value = Str::lower(trim($value));

        if ($value === '') {
            return null;
        }

        if (Str::contains($value, '@')) {
            $value = (string) Str::afterLast($value, '@');
        }

        if (Str::contains($value, '//')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        } else {
            // "acme.com/contact" — with no scheme, `parse_url` reads the whole thing as a path.
            $value = (string) Str::before($value, '/');
        }

        $value = Str::replaceStart('www.', '', trim($value));

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $value) === 1
            ? $value
            : null;
    }

    /**
     * A phone number reduced to its comparable digits, or null when there are too few to be one.
     *
     * Deliberately permissive: refusing a real number means a call placed to somebody who asked us
     * not to be called. A leading `00` international prefix is dropped so `00447700900123` and
     * `+447700900123` agree.
     */
    public static function phone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        $digits = Str::replaceStart('00', '', $digits);

        return Str::length($digits) >= 7 ? $digits : null;
    }

    /**
     * The deterministic lookup key for an already-normalised value. SHA-256, unsalted.
     */
    public static function hash(string $normalised): string
    {
        return hash('sha256', $normalised);
    }

    /**
     * Enough of an address to recognise it, never enough to use it: `j***@example.com`.
     *
     * The domain is shown whole because the domain is the **company**, which is not the private
     * half and is usually already on the lead. Expects a normalised address.
     */
    public static function maskEmail(string $normalised): string
    {
        $local = (string) Str::before($normalised, '@');
        $domain = (string) Str::after($normalised, '@');

        return Str::substr($local, 0, 1).'***@'.$domain;
    }

    /**
     * The last four digits, the way a card is shown: `***4821`.
     *
     * Four is the convention and it is also the point — it is enough for a member to tell two
     * numbers apart on screen and not enough to dial.
     */
    public static function maskPhone(string $value): string
    {
        return '***'.Str::substr($value, -4);
    }
}
