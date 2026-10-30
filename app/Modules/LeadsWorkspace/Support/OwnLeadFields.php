<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Support;

use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadsWorkspace\Actions\CreateLeadAction;
use App\Modules\LeadsWorkspace\Actions\ImportLeadsAction;
use Illuminate\Support\Str;

/**
 * How the company facts a team supplies **themselves** are normalised and keyed — a CSV row
 * ({@see ImportLeadsAction}) or a company typed into the add-a-lead form
 * ({@see CreateLeadAction}).
 *
 * The two ways in share this class rather than a copy each, because they have to agree on one
 * thing in particular: **the identity key**. A member who adds "Bright Bean Coffee" after an event
 * and then imports the spreadsheet that already had it must end up with one lead, not two — which
 * only works if both paths spell the key the same way.
 *
 * The key is company number first, then the website's registrable domain, then the normalised
 * company name. The first two use **exactly** the form Lead Sourcing uses
 * ({@see SourcedLead::dedupeKey()}), so a company the team supplied and one a provider discovered
 * collapse into one lead. The name fallback does not — Lead Sourcing's name key includes the
 * provider that found it — so a name-only record never merges with a discovered lead. That is a
 * recorded limitation rather than an oversight: matching on a bare name across sources is exactly
 * the false merge Enrichment's de-duplication refuses to make.
 *
 * Everything here is also **clamping**: a spreadsheet cell and a form field both have no length
 * limit while the columns do, and an over-long value has to be trimmed here rather than become a
 * "data too long" 500 half way through a write.
 */
final class OwnLeadFields
{
    /**
     * The `leads` columns a team may fill in themselves, with the width of each. Deliberately a
     * short, flat set: what a team knows about a company before Alima has looked at it is a name,
     * where it is, roughly what it does and how big it is. Anything richer belongs to enrichment.
     *
     * @var array<string, int>
     */
    public const array COLUMNS = [
        'company_number' => 255,
        'website' => 255,
        'industry' => 255,
        'employee_range' => 64,
        'locality' => 255,
        'region' => 255,
        'postal_code' => 255,
        'country' => 2,
    ];

    /**
     * The identity the team's own records de-duplicate on, clamped to the column's 191 characters.
     *
     * @param  array<string, string>  $values  at least `company_name`; `company_number` and
     *                                         `website` are used when present
     */
    public static function dedupeKey(array $values): string
    {
        $number = Str::lower(Str::squish($values['company_number'] ?? ''));

        if ($number !== '') {
            return self::clamp('no:'.$number, 191);
        }

        $website = self::safeUrl($values['website'] ?? '');

        if ($website !== '') {
            $host = parse_url($website, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                // Lower-cased **before** the `www.` is stripped, not after. Stripping first is
                // case-sensitive, so a cell reading `WWW.brightbean.co.uk` kept its prefix and
                // keyed as `domain:www.brightbean.co.uk` — a second lead for a company the team
                // already had. A spreadsheet (and a person in a hurry) is exactly where an
                // upper-case `WWW.` gets typed.
                return self::clamp('domain:'.Str::replaceStart('www.', '', Str::lower($host)), 191);
            }
        }

        $name = $values['company_name'] ?? '';

        return self::clamp('name:'.(Str::slug($name) ?: Str::lower(Str::squish($name))), 191);
    }

    /**
     * The company columns from a bag of supplied values, each clamped to its column and with
     * anything blank left out — so a caller can spread the result over a create, or diff it against
     * a lead it is filling the gaps in, without either having to re-check for empties.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    public static function companyFields(array $values): array
    {
        $fields = [];

        foreach (self::COLUMNS as $column => $width) {
            $supplied = $values[$column] ?? '';

            // Two columns are not plain text: a website has to be one we are willing to render as
            // a link, and a country is a two-letter code or nothing at all.
            $value = match ($column) {
                'website' => self::safeUrl($supplied),
                'country' => self::country($supplied),
                default => self::clamp($supplied, $width),
            };

            if ($value !== '') {
                $fields[$column] = $value;
            }
        }

        return $fields;
    }

    /**
     * A two-letter ISO code upper-cased, or `''` — the column is `char(2)`, and someone who typed
     * "United Kingdom" should not have it silently truncated to "UN".
     */
    public static function country(string $value): string
    {
        $value = trim($value);

        return Str::length($value) === 2 ? Str::upper($value) : '';
    }

    /**
     * Trim a value to its column's width. Multibyte-safe, so clamping an Arabic company name can't
     * leave a half character behind.
     */
    public static function clamp(string $value, int $max): string
    {
        return Str::limit(Str::squish($value), $max, '');
    }

    /**
     * A website only if it is one we are willing to render as a link. The detail screen turns this
     * column into an `<a href>`, so a value of `javascript:…` has to not reach it — the same rule
     * {@see SourcedLead} applies to provider data. A bare domain is what a spreadsheet (and a
     * person in a hurry) usually holds, and is safe to assume `https` for.
     */
    public static function safeUrl(string $value): string
    {
        $value = self::clamp($value, 255);

        if ($value === '') {
            return '';
        }

        $candidate = Str::startsWith(Str::lower($value), ['http://', 'https://'])
            ? $value
            : (preg_match('/^[\pL\pN][\pL\pN.\-]*\.[\pL]{2,}/u', $value) === 1 ? 'https://'.$value : '');

        if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        return self::clamp($candidate, 255);
    }

    /**
     * Tags from one field, however they were separated — including the **Arabic** comma, which an
     * Arabic-language spreadsheet (or an Arabic keyboard) will have used (ADR 0007: the UAE is an
     * MVP market).
     *
     * @return list<string>
     */
    public static function splitTags(string $value): array
    {
        /** @var list<string> $tags */
        $tags = collect(preg_split('/[,;|،؛]/u', $value) ?: [])
            ->map(static fn (string $tag): string => Str::limit(Str::squish($tag), 40, ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $tags;
    }
}
