<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use Illuminate\Support\Str;

/**
 * Discovers UK companies from the official Companies House public-data API (free; ADR 0008). Uses
 * the `/search/companies` endpoint, mapping each result into a {@see SourcedLead} with the public
 * record URL as its source attribution. Hardening (timeouts, retry, circuit breaker, error → domain
 * exception) is inherited from {@see AbstractHttpLeadSourceProvider}. Returns only company-level
 * facts — no personal data. Config: `config/lead-sourcing.php`.
 */
final class CompaniesHouseProvider extends AbstractHttpLeadSourceProvider
{
    public function source(): LeadSource
    {
        return LeadSource::CompaniesHouse;
    }

    public function discover(DiscoveryQuery $query): array
    {
        $terms = trim($query->terms);
        if ($terms === '') {
            return [];
        }

        $apiKey = $this->requireApiKey('lead-sourcing.providers.companies_house.api_key');
        $baseUrl = rtrim((string) config('lead-sourcing.providers.companies_house.base_url'), '/');
        $perPage = max(1, min($query->limit, 100)); // Companies House caps items_per_page at 100.

        $response = $this->guarded(fn () => $this->http
            ->baseUrl($baseUrl)
            ->withBasicAuth($apiKey, '')
            ->timeout((int) config('lead-sourcing.providers.companies_house.timeout', 10))
            ->connectTimeout((int) config('lead-sourcing.providers.companies_house.connect_timeout', 5))
            ->retry(
                (int) config('lead-sourcing.providers.companies_house.retry_times', 2),
                (int) config('lead-sourcing.providers.companies_house.retry_sleep_ms', 250),
                $this->retryWhen(),
                throw: false,
            )
            ->acceptJson()
            ->get('/search/companies', [
                'q' => $terms,
                'items_per_page' => $perPage,
            ]));

        /** @var array<array-key, mixed> $items */
        $items = (array) $response->json('items', []);

        $leads = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $lead = $this->mapItem($item);
            if ($lead instanceof SourcedLead) {
                $leads[] = $lead;
            }

            if (count($leads) >= $query->limit) {
                break;
            }
        }

        return $leads;
    }

    public function costMicros(int $leadsReturned): int
    {
        // Companies House is free.
        return 0;
    }

    /**
     * Map one `/search/companies` result item into a {@see SourcedLead}. Returns null when the item
     * has no usable company name. Companies House search does not include SIC codes or a website —
     * Enrichment (Phase 8) fills those in later.
     *
     * @param  array<array-key, mixed>  $item
     */
    private function mapItem(array $item): ?SourcedLead
    {
        $name = trim((string) ($item['title'] ?? ''));
        if ($name === '') {
            return null;
        }

        $number = isset($item['company_number']) ? trim((string) $item['company_number']) : null;

        /** @var array<string, mixed> $address */
        $address = is_array($item['address'] ?? null) ? $item['address'] : [];

        return new SourcedLead(
            source: LeadSource::CompaniesHouse,
            companyName: $name,
            companyNumber: $number !== '' ? $number : null,
            companyStatus: isset($item['company_status']) ? (string) $item['company_status'] : null,
            companyType: isset($item['company_type']) ? (string) $item['company_type'] : null,
            incorporatedOn: isset($item['date_of_creation']) ? (string) $item['date_of_creation'] : null,
            website: null,
            locality: isset($address['locality']) ? (string) $address['locality'] : null,
            region: isset($address['region']) ? (string) $address['region'] : null,
            postalCode: isset($address['postal_code']) ? (string) $address['postal_code'] : null,
            // Companies House is the UK registry — the jurisdiction is GB regardless of the address
            // country string, which is a free-text name rather than an ISO code.
            country: 'GB',
            address: $address,
            sicCodes: [],
            sourceUrl: $number !== null && $number !== '' ? $this->recordUrl($number) : null,
            raw: $item,
        );
    }

    private function recordUrl(string $number): string
    {
        $base = rtrim((string) config('lead-sourcing.providers.companies_house.record_base_url'), '/');

        return $base.'/company/'.Str::of($number)->trim()->value();
    }
}
