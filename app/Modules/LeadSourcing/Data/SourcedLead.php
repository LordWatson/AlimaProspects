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
    ) {}

    /**
     * A normalised identity key for the dedupe placeholder (Phase 8 does full de-duplication):
     * prefer the registry number, else the website domain, else a slug of the name. Never empty.
     */
    public function dedupeKey(): string
    {
        $number = trim((string) $this->companyNumber);
        if ($number !== '') {
            return $this->source->value.':no:'.Str::lower($number);
        }

        $domain = $this->domain();
        if ($domain !== null) {
            return 'domain:'.$domain;
        }

        // Name-only is the weakest key: clamp the slug (so it can't exceed the column) and append a
        // short hash of the full name so two genuinely different same-prefix names don't collide.
        $slug = Str::slug($this->companyName);

        return 'name:'.Str::limit($slug, 150, '').':'.substr(sha1($this->companyName), 0, 12);
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
            'website' => $this->text($this->website),
            'locality' => $this->text($this->locality),
            'region' => $this->text($this->region),
            'postal_code' => $this->text($this->postalCode),
            'country' => $this->country !== null ? Str::upper(Str::substr(trim($this->country), 0, 2)) : null,
            'address' => $this->address === [] ? null : $this->address,
            'sic_codes' => $this->sicCodes === [] ? null : $this->sicCodes,
            // Clamp to the column width (string(255)); a hostile/overlong URL must not fail the write.
            'source_url' => $this->sourceUrl !== null ? Str::limit($this->sourceUrl, 255, '') : null,
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
