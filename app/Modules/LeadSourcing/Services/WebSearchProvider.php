<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use Illuminate\Http\Client\Response;
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

    /**
     * How many requests the last {@see self::discover()} made. The contract's `costMicros()` takes a
     * lead count, but this provider is billed per request and now makes several, so the count is
     * remembered between the two calls the job makes back to back.
     */
    private int $requestsMade = 0;

    public function discover(DiscoveryQuery $query): array
    {
        $this->requestsMade = 0;

        // The main query, then the other angles the shaper wrote (a second trade, lookalikes of the
        // team's existing customers). One broad query was the baseline's weakest source: several
        // narrow ones each surface a different slice of the same customers.
        $angles = array_slice(
            array_values(array_unique(array_filter([trim($query->terms), ...$query->queries]))),
            0,
            max(1, (int) config('lead-sourcing.providers.web_search.max_queries', 3)),
        );

        if ($angles === []) {
            return [];
        }

        $driver = (string) config('lead-sourcing.providers.web_search.provider', 'serper');
        if ($driver !== 'serper') {
            // Only Serper is implemented in the MVP (ADR 0010); anything else is a config error, not
            // a transient failure — fail clearly rather than silently returning nothing.
            throw LeadSourcingException::providerUnavailable($this->source()->value);
        }

        /** @var list<string> $ignore */
        $ignore = (array) config('lead-sourcing.providers.web_search.ignore_domains', []);
        $perQuery = max(10, (int) ceil($query->limit / count($angles)));

        $leads = [];
        $seenDomains = [];

        foreach ($angles as $angle) {
            foreach ($this->organic($angle, $query, $perQuery) as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $lead = $this->mapResult([...$result, 'query' => $angle]);
                if (! $lead instanceof SourcedLead) {
                    continue;
                }

                $domain = $lead->domain();

                // Skip aggregator/social/directory domains — they aren't a company's own site, and
                // would otherwise all collapse into a single `domain:` lead.
                if ($domain !== null && $this->isIgnored($domain, $ignore)) {
                    continue;
                }

                // Collapse multiple pages from the same site, within a response and across angles.
                if ($domain !== null) {
                    if (isset($seenDomains[$domain])) {
                        continue;
                    }
                    $seenDomains[$domain] = true;
                }

                $leads[] = $lead;
            }
        }

        return array_slice($leads, 0, $query->limit);
    }

    public function costMicros(int $leadsReturned): int
    {
        // Billed per request, whether or not it returned anything we kept.
        return $this->requestsMade * (int) config('lead-sourcing.providers.web_search.cost_micros_per_request', 3_000);
    }

    /**
     * One search request's organic results.
     *
     * @return array<array-key, mixed>
     */
    private function organic(string $terms, DiscoveryQuery $query, int $num): array
    {
        $apiKey = $this->requireApiKey('lead-sourcing.providers.web_search.api_key');
        $baseUrl = rtrim((string) config('lead-sourcing.providers.web_search.base_url'), '/');

        $post = function (string $q) use ($apiKey, $baseUrl, $num): Response {
            $this->requestsMade++;

            return $this->http
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
                ->post('/search', array_filter(['q' => $q, 'num' => min($num, 100), 'gl' => $this->country()]));
        };

        $response = $this->guarded(function () use ($post, $terms, $query): Response {
            $response = $post($this->searchQuery($terms, $query));

            // Serper refuses some query patterns outright on some account tiers — a quoted negative
            // with more than ten results on the free tier answers `400 Query pattern not allowed`.
            // That is a verdict about the query, not about the provider, and the exclusions are
            // applied again after the fact by {@see LeadCandidateFilter}; so ask once more without
            // them rather than failing a run the plain query would have served.
            if ($response->status() === 400 && str_contains(Str::lower($response->body()), 'not allowed')) {
                return $post($this->searchQuery($terms, $query, withExclusions: false));
            }

            return $response;
        });

        return (array) $response->json('organic', []);
    }

    /**
     * The country to search from, as the provider's `gl` hint, from the market the team sells
     * into. Without it a place name is read wherever it is most famous: a search for foundries in
     * "Birmingham" came back with Alabama's (relevance eval, P1).
     */
    private function country(): ?string
    {
        // The ambient team, which every discovery job runs inside. No team in context (a console
        // probe, a unit test) means no hint, and the provider's own default.
        return match (Market::tryFrom((string) app(TeamContext::class)->team()?->market)) {
            Market::Uk => 'gb',
            Market::Uae => 'ae',
            default => null,
        };
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
    private function searchQuery(string $terms, DiscoveryQuery $query, bool $withExclusions = true): string
    {
        $location = trim((string) $query->location);

        $q = $location !== '' ? "{$terms} {$location}" : $terms;

        foreach ($withExclusions ? array_slice($query->excludeTerms, 0, self::MAX_NEGATIVE_TERMS) : [] as $exclude) {
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
