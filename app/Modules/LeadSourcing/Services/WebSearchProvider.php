<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use Illuminate\Support\Str;

/**
 * Discovers company candidates from a web-search API (ADR 0010 — Serper is the MVP driver). Maps each
 * organic result's title → company name and link → website, leaning on the domain-based dedupe key so
 * results collapse with Google Places across providers. Coarser than a registry (no company number /
 * status); Enrichment (Phase 8) can reuse this provider to fetch pages. Hardening inherited from
 * {@see AbstractHttpLeadSourceProvider}. Config: `config/lead-sourcing.php`.
 *
 * Alone among the providers it supports negative terms, so the query's exclusions are pushed down to
 * the API here rather than only filtered out of the response — see {@see self::searchQuery()}.
 */
final class WebSearchProvider extends AbstractHttpLeadSourceProvider
{
    /**
     * How many of the query's negative terms become `-"..."` operators. Past a handful they stop
     * sharpening the search and start suppressing it.
     */
    private const int MAX_NEGATIVE_TERMS = 5;

    public function source(): LeadSource
    {
        return LeadSource::WebSearch;
    }

    public function discover(DiscoveryQuery $query): array
    {
        $q = $this->searchQuery($query);
        if ($q === '') {
            return [];
        }

        $driver = (string) config('lead-sourcing.providers.web_search.provider', 'serper');
        if ($driver !== 'serper') {
            // Only Serper is implemented in the MVP (ADR 0010); anything else is a config error, not
            // a transient failure — fail clearly rather than silently returning nothing.
            throw LeadSourcingException::providerUnavailable($this->source()->value);
        }

        $apiKey = $this->requireApiKey('lead-sourcing.providers.web_search.api_key');
        $baseUrl = rtrim((string) config('lead-sourcing.providers.web_search.base_url'), '/');
        $num = max(1, min($query->limit, 100));

        $response = $this->guarded(fn () => $this->http
            ->baseUrl($baseUrl)
            ->withHeaders(['X-API-KEY' => $apiKey])
            ->timeout((int) config('lead-sourcing.providers.web_search.timeout', 10))
            ->connectTimeout((int) config('lead-sourcing.providers.web_search.connect_timeout', 5))
            ->retry(
                (int) config('lead-sourcing.providers.web_search.retry_times', 2),
                (int) config('lead-sourcing.providers.web_search.retry_sleep_ms', 250),
                $this->retryWhen(),
                throw: false,
            )
            ->acceptJson()
            ->post('/search', [
                'q' => $q,
                'num' => $num,
            ]));

        /** @var array<array-key, mixed> $organic */
        $organic = (array) $response->json('organic', []);

        /** @var list<string> $ignore */
        $ignore = (array) config('lead-sourcing.providers.web_search.ignore_domains', []);

        $leads = [];
        $seenDomains = [];

        foreach ($organic as $result) {
            if (! is_array($result)) {
                continue;
            }

            $lead = $this->mapResult($result);
            if (! $lead instanceof SourcedLead) {
                continue;
            }

            $domain = $lead->domain();

            // Skip aggregator/social/directory domains — they aren't a company's own site, and
            // would otherwise all collapse into a single `domain:` lead.
            if ($domain !== null && $this->isIgnored($domain, $ignore)) {
                continue;
            }

            // Collapse multiple pages from the same site within a single response.
            if ($domain !== null) {
                if (isset($seenDomains[$domain])) {
                    continue;
                }
                $seenDomains[$domain] = true;
            }

            $leads[] = $lead;

            if (count($leads) >= $query->limit) {
                break;
            }
        }

        return $leads;
    }

    public function costMicros(int $leadsReturned): int
    {
        // Billed per query (a run makes one); a no-op run (empty terms → no request) shows no cost.
        if ($leadsReturned === 0) {
            return 0;
        }

        return (int) config('lead-sourcing.providers.web_search.cost_micros_per_request', 3_000);
    }

    /**
     * Compose the search query from the shaped terms, the optional location, and the query's negative
     * terms as Google `-"..."` operators.
     *
     * This is the one provider that can refuse the wrong companies at the API rather than after the
     * fact, and it matters most here: a web search for the work a business does returns the businesses
     * that do that work — its competitors — near the top, because they are the ones who write about
     * it. Excluding them in the query buys back those result slots for actual prospects instead of
     * spending them and discarding the results ({@see LeadCandidateFilter} still runs, as the
     * backstop and for the other providers).
     *
     * Terms are quoted so a multi-word exclusion is treated as a phrase, and the negatives are capped
     * so the operators can never crowd out the query itself.
     */
    private function searchQuery(DiscoveryQuery $query): string
    {
        $terms = trim($query->terms);
        $location = trim((string) $query->location);

        if ($terms === '') {
            return '';
        }

        $q = $location !== '' ? "{$terms} {$location}" : $terms;

        foreach (array_slice($query->excludeTerms, 0, self::MAX_NEGATIVE_TERMS) as $exclude) {
            $exclude = trim(str_replace('"', '', $exclude));

            if ($exclude === '') {
                continue;
            }

            $q .= ' -"'.$exclude.'"';
        }

        return $q;
    }

    /**
     * Map one organic result into a {@see SourcedLead}. The title is trimmed to the part before a
     * common separator (" - ", " | ", …) so it reads as a company name rather than a page title.
     *
     * @param  array<array-key, mixed>  $result
     */
    private function mapResult(array $result): ?SourcedLead
    {
        $link = is_string($result['link'] ?? null) ? trim($result['link']) : '';
        $title = is_string($result['title'] ?? null) ? trim($result['title']) : '';

        if ($link === '' || $title === '' || ! Str::startsWith(Str::lower($link), ['http://', 'https://'])) {
            return null;
        }

        return new SourcedLead(
            source: LeadSource::WebSearch,
            // Store the site root as the website (cleaner domain), but keep the exact result page as
            // the source URL (the actual provenance).
            companyName: $this->companyName($title),
            website: $this->origin($link) ?? $link,
            sourceUrl: $link,
            raw: $result,
        );
    }

    /**
     * The scheme+host origin of a URL (e.g. `https://acme.com`), or null if it can't be parsed.
     */
    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! is_string($scheme) || ! is_string($host) || $host === '') {
            return null;
        }

        return "{$scheme}://{$host}";
    }

    /**
     * Whether a domain (or any parent of it) is on the ignore list.
     *
     * @param  list<string>  $ignore
     */
    private function isIgnored(string $domain, array $ignore): bool
    {
        foreach ($ignore as $blocked) {
            $blocked = Str::lower((string) $blocked);
            if ($domain === $blocked || Str::endsWith($domain, '.'.$blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce a page title to a likely company name: the first segment before a separator.
     */
    private function companyName(string $title): string
    {
        foreach ([' | ', ' - ', ' – ', ' — ', ' · '] as $separator) {
            if (Str::contains($title, $separator)) {
                return trim(Str::before($title, $separator));
            }
        }

        return $title;
    }
}
