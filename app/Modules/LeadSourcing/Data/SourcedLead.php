<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Data;

use App\Modules\LeadSourcing\Contracts\LeadSourceProvider;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * One company-level lead as a provider normalises it, before it is persisted as a {@see Lead}.
 * Every {@see LeadSourceProvider} yields these so the discovery
 * job never sees a provider's raw shape. All fields except the name and source are optional — providers
 * differ in what they return — and free-text is clamped so a hostile payload can't bloat storage.
 */
final class SourcedLead extends Data
{
    private const int MAX_TEXT = 255;

    /**
     * @param  array<array-key, mixed>  $address
     * @param  list<string>  $sicCodes
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public LeadSource $source,
        public string $companyName,
        public ?string $companyNumber = null,
        public ?string $companyStatus = null,
        public ?string $companyType = null,
        public ?string $incorporatedOn = null,
        public ?string $website = null,
        public ?string $locality = null,
        public ?string $region = null,
        public ?string $postalCode = null,
        public ?string $country = null,
        public array $address = [],
        public array $sicCodes = [],
        public ?string $sourceUrl = null,
        public array $raw = [],
        // A provider-specific stable id (e.g. a Google Places place id) used to dedupe website-less
        // records that a shared name would otherwise wrongly merge.
        public ?string $externalId = null,
    ) {}

    /**
     * A normalised identity key for the dedupe placeholder (Phase 8 does full de-duplication):
     * registry number → website domain → provider external id → slug+hash of the name. Number and
     * domain are **source-agnostic** so the same company found by two providers de-duplicates (ADR
     * 0008 / Phase 7b); the external-id and name fallbacks are provider-scoped (a place id or a bare
     * name can't be trusted to mean the same company across sources). Never empty.
     */
    public function dedupeKey(): string
    {
        $number = trim((string) $this->companyNumber);
        if ($number !== '') {
            return 'no:'.Str::lower($number);
        }

        $domain = $this->domain();
        if ($domain !== null) {
            return 'domain:'.$domain;
        }

        $externalId = trim((string) $this->externalId);
        if ($externalId !== '') {
            return 'ext:'.$this->source->value.':'.Str::lower($externalId);
        }

        // Name-only is the weakest key and provider-scoped: clamp the slug (so it can't exceed the
        // column) and append a short hash of that slug — case-insensitively — so distinct same-prefix
        // names don't collide while "ACME" and "Acme" still match.
        $slug = Str::slug($this->companyName);

        return 'name:'.$this->source->value.':'.Str::limit($slug, 120, '').':'.substr(sha1($slug), 0, 12);
    }

    /**
     * The bare registrable domain of the website, lower-cased and without a leading `www.`, or null.
     */
    public function domain(): ?string
    {
        $website = trim((string) $this->website);
        if ($website === '') {
            return null;
        }

        $host = parse_url(Str::startsWith($website, ['http://', 'https://']) ? $website : 'https://'.$website, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return Str::lower(Str::replaceStart('www.', '', $host));
    }

    /**
     * The persisted attributes for a {@see Lead}, with source
     * attribution attached. `collected_at` marks when the record was read from the provider.
     *
     * @return array<string, mixed>
     */
    public function toLeadAttributes(): array
    {
        return [
            'source' => $this->source,
            'company_name' => $this->text($this->companyName) ?? '',
            'company_number' => $this->text($this->companyNumber),
            'company_status' => $this->text($this->companyStatus),
            'company_type' => $this->text($this->companyType),
            'incorporated_on' => $this->date($this->incorporatedOn),
            // URLs are rendered as links in the UI — only http(s) is stored (a `javascript:` or other
            // scheme is dropped), and the value is length-clamped to the column.
            'website' => $this->safeUrl($this->website),
            'locality' => $this->text($this->locality),
            'region' => $this->text($this->region),
            'postal_code' => $this->text($this->postalCode),
            'country' => $this->country !== null ? Str::upper(Str::substr(trim($this->country), 0, 2)) : null,
            'address' => $this->address === [] ? null : $this->address,
            'sic_codes' => $this->sicCodes === [] ? null : $this->sicCodes,
            'source_url' => $this->safeUrl($this->sourceUrl),
            'raw' => $this->raw === [] ? null : $this->raw,
            'collected_at' => now(),
            'dedupe_key' => $this->dedupeKey(),
        ];
    }

    private function text(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : Str::limit($trimmed, self::MAX_TEXT, '');
    }

    /**
     * Accept only an http(s) URL (clamped to the column); anything else — a non-http scheme, or junk
     * — is dropped so it can never be stored or rendered as a link.
     */
    private function safeUrl(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '' || ! Str::startsWith(Str::lower($trimmed), ['http://', 'https://'])) {
            return null;
        }

        return Str::limit($trimmed, self::MAX_TEXT, '');
    }

    private function date(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
