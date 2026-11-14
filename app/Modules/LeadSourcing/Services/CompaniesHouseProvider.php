<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Discovers UK companies from the official Companies House public-data API (free; ADR 0008). Uses
 * `/advanced-search/companies` (by SIC code, active companies, in a place) when the query carries
 * SIC codes and the `/search/companies` name search otherwise, mapping each result into a {@see SourcedLead} with the public
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
        if ($terms === '' && $query->sicCodes === []) {
            return [];
        }

        $perPage = max(1, min($query->limit, 100)); // Companies House caps items_per_page at 100.

        // By sector first, where the query carries SIC codes: the register filters by code, status
        // and place, which the name search cannot do. The name search matched "property management"
        // against company names nationwide and returned dissolved companies with them — 30%
        // precision in the relevance baseline.
        $leads = $query->sicCodes === [] ? [] : $this->search('/advanced-search/companies', array_filter([
            'sic_codes' => implode(',', $query->sicCodes),
            'company_status' => 'active',
            'location' => trim((string) $query->location),
            'size' => $perPage,
        ]), $query->limit);

        // Codes the register does not hold (or nothing in that place) fall back to the name search
        // rather than returning an empty run.
        if ($leads === [] && $terms !== '') {
            $leads = $this->search('/search/companies', ['q' => $terms, 'items_per_page' => $perPage], $query->limit);
        }

        return $leads;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<SourcedLead>
     */
    private function search(string $path, array $params, int $limit): array
    {
        $apiKey = $this->requireApiKey('lead-sourcing.providers.companies_house.api_key');
        $baseUrl = rtrim((string) config('lead-sourcing.providers.companies_house.base_url'), '/');

        $response = $this->guarded(function () use ($baseUrl, $apiKey, $path, $params): Response {
            $response = $this->http
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
                ->get($path, $params);

            // The advanced search answers "no companies found" with a 404. That is an empty
            // result, not a failure, and must not fail the run.
            return $response->status() === 404
                ? new Response(new PsrResponse(200, [], '{"items":[]}'))
                : $response;
        });

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

            if (count($leads) >= $limit) {
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
        // The two endpoints name the same things differently: `title`/`address` on the name search,
        // `company_name`/`registered_office_address` on the advanced one.
        $name = trim((string) ($item['title'] ?? $item['company_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        // A dissolved or insolvent company is not a prospect. The name search returns them
        // unasked; a status the register did not state is kept.
        $status = isset($item['company_status']) ? (string) $item['company_status'] : null;
        if ($status !== null && ! in_array($status, ['active', 'open'], true)) {
            return null;
        }

        $number = isset($item['company_number']) ? trim((string) $item['company_number']) : null;

        /** @var array<string, mixed> $address */
        $address = match (true) {
            is_array($item['address'] ?? null) => $item['address'],
            is_array($item['registered_office_address'] ?? null) => $item['registered_office_address'],
            default => [],
        };

        return new SourcedLead(
            source: LeadSource::CompaniesHouse,
            companyName: $name,
            companyNumber: $number !== '' ? $number : null,
            companyStatus: $status,
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
            sicCodes: array_values(array_filter((array) ($item['sic_codes'] ?? []), is_string(...))),
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
