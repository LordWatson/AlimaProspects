<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Enrichment\Actions\EnrichLeadAction;
use App\Modules\LeadSourcing\Contracts\CompanyWebsiteResolver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Resolves a company's likely homepage from the web-search provider (Serper, ADR 0010), reusing the
 * same credentials and endpoint as {@see WebSearchProvider}. It runs a single query for the company
 * name (plus an optional place) and returns the origin of the first organic result that is a
 * company's own site — skipping the aggregator/social/directory hosts the provider already filters
 * and the free-mail/site-builder hosts Enrichment de-duplication refuses to treat as an identity.
 *
 * It is **best-effort and never throws**: a missing name, a disabled or unconfigured provider, an
 * open breaker, or any HTTP failure all return null, because website resolution is a convenience
 * Enrichment falls back to and must never be allowed to fail an enrichment run. It shares the lead
 * sourcing circuit breaker under the `web_search` key, so a Serper outage already tripped by a
 * discovery run short-circuits here too (and vice versa) — the upstream is the same.
 *
 * The result is a candidate only: confirming it actually belongs to the company is the caller's job
 * ({@see EnrichLeadAction}), because a search returns competitors and
 * listings near the top for exactly the words that name a business.
 */
final class SerperCompanyWebsiteResolver implements CompanyWebsiteResolver
{
    /** The circuit-breaker key — deliberately shared with the web-search provider's upstream. */
    private const string BREAKER_KEY = 'web_search';

    /** How many organic results to consider before giving up. */
    private const int RESULTS = 5;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function resolve(string $companyName, ?string $location = null): ?string
    {
        $name = trim($companyName);

        if ($name === '') {
            return null;
        }

        if ((string) config('lead-sourcing.providers.web_search.provider', 'serper') !== 'serper') {
            return null; // only Serper is implemented (ADR 0010); anything else resolves nothing
        }

        $apiKey = (string) config('lead-sourcing.providers.web_search.api_key');
        if ($apiKey === '') {
            return null; // unconfigured — resolution is simply unavailable, not an error
        }

        if (! $this->breaker->allows(self::BREAKER_KEY)) {
            return null; // the upstream is already known to be down
        }

        $location = trim((string) $location);
        $query = $location !== '' ? "{$name} {$location}" : $name;

        $baseUrl = rtrim((string) config('lead-sourcing.providers.web_search.base_url'), '/');

        try {
            $response = $this->http
                ->baseUrl($baseUrl)
                ->withHeaders(['X-API-KEY' => $apiKey])
                ->timeout((int) config('lead-sourcing.providers.web_search.timeout', 10))
                ->connectTimeout((int) config('lead-sourcing.providers.web_search.connect_timeout', 5))
                ->acceptJson()
                ->post('/search', ['q' => $query, 'num' => self::RESULTS]);
        } catch (Throwable $e) {
            // A network-level failure is transient — trip the breaker, then degrade to null.
            $this->breaker->recordFailure(self::BREAKER_KEY);
            Log::channel('lead_sourcing')->warning('Website resolution request failed.', [
                'company' => $name,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            // Only a transient failure trips the breaker; a 4xx is our key/query and stays shut.
            if ($response->serverError() || $response->status() === 429) {
                $this->breaker->recordFailure(self::BREAKER_KEY);
            }

            return null;
        }

        $this->breaker->recordSuccess(self::BREAKER_KEY);

        return $this->firstCompanySite($response->json('organic', []));
    }

    /**
     * The origin of the first organic result that looks like a company's own website.
     */
    private function firstCompanySite(mixed $organic): ?string
    {
        if (! is_array($organic)) {
            return null;
        }

        /** @var list<string> $ignore */
        $ignore = (array) config('lead-sourcing.providers.web_search.ignore_domains', []);
        /** @var list<string> $blocked */
        $blocked = (array) config('enrichment.blocked_dedupe_domains', []);
        $skip = array_merge($ignore, $blocked);

        foreach ($organic as $result) {
            if (! is_array($result)) {
                continue;
            }

            $link = is_string($result['link'] ?? null) ? trim($result['link']) : '';

            if ($link === '' || ! Str::startsWith(Str::lower($link), ['http://', 'https://'])) {
                continue;
            }

            $parts = parse_url($link);
            $scheme = $parts['scheme'] ?? null;
            $host = $parts['host'] ?? null;

            if (! is_string($scheme) || ! is_string($host) || $host === '') {
                continue;
            }

            $domain = Str::lower(Str::replaceStart('www.', '', $host));

            if ($this->isSkipped($domain, $skip)) {
                continue;
            }

            return "{$scheme}://{$host}";
        }

        return null;
    }

    /**
     * Whether a domain (or any parent of it) is on the skip list.
     *
     * @param  list<string>  $skip
     */
    private function isSkipped(string $domain, array $skip): bool
    {
        foreach ($skip as $blocked) {
            $blocked = Str::lower((string) $blocked);

            if ($blocked !== '' && ($domain === $blocked || Str::endsWith($domain, '.'.$blocked))) {
                return true;
            }
        }

        return false;
    }
}
