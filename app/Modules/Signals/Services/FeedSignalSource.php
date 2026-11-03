<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Core\Support\Security\SafeUrl;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Exceptions\SourceAddressNotFound;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Support\FeedDocument;
use App\Modules\Signals\Support\IgnoredDomains;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * An RSS or Atom feed a team chose, as a signal source (S13b, ADR 0023).
 *
 * **What it is for.** The two Serper sources search what Google indexed. A feed is the opposite: a
 * team names a publication they already trust — an industry newsletter, a regulator's filings feed,
 * a trade portal's press releases — and every item in it is read. For a niche sector that is often
 * better than any search, because the publication nobody else reads is exactly the one a competitor
 * is not watching.
 *
 * **Its items are not structured.** Unlike Companies House (ADR 0023 §6), a feed entry is prose
 * somebody wrote, so it carries `classification: null` and goes through `signals.extract_event` like
 * a news article. The registry can assert what an event is; a newsletter cannot.
 *
 * **It is the one source whose address a customer supplies, so it is the one with an SSRF surface.**
 * Five bounds, each of them a condition of fetching rather than a nicety:
 *
 * 1. **{@see SafeUrl}** — Phase 20's guard, shared with `HttpWebContentFetcher` rather than copied:
 *    http(s) only, ports 80/443 only, and no host that resolves to loopback, private, link-local or
 *    reserved space. `169.254.169.254` is the cloud metadata endpoint and reaching it is how an
 *    SSRF becomes a credential leak. Redirects are **not followed**, because the first hop being
 *    public says nothing about the second and this client has no reason to chase one.
 * 2. **A conditional GET.** The `ETag` and `Last-Modified` of the last fetch are cached per team
 *    **and** feed URL, so a feed that has not changed answers `304` with no body — which is most
 *    polls, and the difference between being a good citizen of somebody else's bandwidth and being
 *    blocked by them. Per team is the S13b review's finding and it is a tenancy rule rather than a
 *    tuning choice: see {@see self::cacheKey()}.
 * 3. **A byte cap**, read off a streamed body, so a feed that is secretly a 2GB file cannot exhaust
 *    a queue worker.
 * 4. **A poll-interval floor.** A watch may run hourly; a feed is not re-fetched more often than
 *    `signals.sources.feed.min_poll_minutes` whatever the cadence, because the cost of being
 *    impolite to a small publisher is being banned. Per team, for the same reason as the
 *    validators, and bypassed by a preview ({@see SignalSourceQuery::$preview}).
 * 5. **A per-team feed count**, enforced on the save path, because every feed is a standing
 *    outbound request from our infrastructure to an address a customer chose.
 */
final class FeedSignalSource extends AbstractHttpSignalSourceProvider
{
    /** Cache key prefix for the conditional-GET validators, per team **and** feed URL. */
    private const string VALIDATOR_PREFIX = 'signals:feed:validators:';

    /** Cache key prefix for the poll-interval floor, per team **and** feed URL. */
    private const string POLLED_PREFIX = 'signals:feed:polled:';

    public function __construct(
        HttpFactory $http,
        CircuitBreaker $breaker,
        private readonly SignalItemNormaliser $normaliser,
        private readonly IgnoredDomains $ignored,
        private readonly Cache $cache,
    ) {
        parent::__construct($http, $breaker);
    }

    public function kind(): SignalSourceKind
    {
        return SignalSourceKind::Feed;
    }

    /**
     * Only a definition that names a usable feed URL.
     *
     * A **decline rather than a failure** (`SignalSourceProvider::supports()`): a definition whose
     * feed URL is missing or unsafe contributes no queries, which is what a misconfiguration should
     * cost. The address is re-checked here and not only at save time, because `SafeUrl` depends on
     * DNS and a host that was public last week can point at `127.0.0.1` today — a rebinding attack
     * is precisely the move of pointing a once-legitimate name somewhere internal.
     */
    public function supports(SignalDefinition $definition): bool
    {
        return $this->feedUrl($definition->sourceConfig($this->kind())) !== null;
    }

    /**
     * Free to fetch. What a feed costs is the AI that classifies what comes out of it, which is
     * metered where every other AI call is.
     */
    public function estimatedCostMicros(int $queries): int
    {
        return 0;
    }

    public function fetch(SignalSourceQuery $query): array
    {
        $configured = $this->configuredUrl($query->config);

        // No feed configured at all: nothing to fetch, and not a failure. A definition that names
        // this source without a URL contributes nothing, the way one with no templates does.
        if ($configured === null) {
            return [];
        }

        $url = $this->feedUrl($query->config);

        // Configured, but **not an address we may fetch**. Distinguished from the case above
        // deliberately: silently returning nothing here would hide a feed that stopped being safe
        // between being saved and being polled — which is exactly what a DNS-rebinding attack looks
        // like — so it is reported, non-retryably, with the source named.
        if ($url === null) {
            Log::channel('signals')->warning('Feed refused by the SSRF guard before any request.', [
                'source' => $this->kind()->value,
            ]);

            throw SignalsException::sourceMisconfigured($this->kind()->value);
        }

        // **A preview holds none of the run's state** (S13b review, finding 4). It neither honours
        // the floor nor stamps it, and `conditionalGet()` sends no validators for one, so pressing
        // Preview cannot leave the feed 304-ing the first real run. Its own three caps and its
        // ledger row are what bound it instead (ADR 0020).
        $scope = $this->cacheKey($query, $url);

        if ($scope !== null && $this->polledTooRecently($scope)) {
            Log::channel('signals')->info('Feed skipped: inside the poll-interval floor.', [
                'source' => $this->kind()->value,
                'host' => $this->ignored->hostOf($url),
            ]);

            return [];
        }

        $response = $this->conditionalGet($url, $scope);

        // Nothing has changed since the last poll. Not an error and not an empty feed — the
        // publisher is telling us we already have it, which is the whole point of sending the
        // validators.
        if ($response->status() === 304) {
            $this->rememberPoll($scope);

            return [];
        }

        $body = $this->readCapped($response);

        $this->rememberValidators($scope, $response);
        $this->rememberPoll($scope);

        $document = FeedDocument::parse($body);

        if ($document === null) {
            Log::channel('signals')->warning('Feed body could not be parsed as RSS or Atom.', [
                'source' => $this->kind()->value,
                'host' => $this->ignored->hostOf($url),
                'bytes' => strlen($body),
            ]);

            throw SignalsException::sourceFailed($this->kind()->value);
        }

        return $this->mapEntries($document, $query);
    }

    /**
     * The guarded, conditional, non-redirecting GET.
     *
     * `assert()` runs **immediately before** the request rather than at save time only: `SafeUrl`
     * resolves DNS, and a host that was public when the member pasted it can point somewhere
     * internal by the time a worker fetches it.
     */
    private function conditionalGet(string $url, ?string $scope): Response
    {
        $safe = SafeUrl::normalise($url);

        if ($safe === null) {
            throw SignalsException::sourceMisconfigured($this->kind()->value);
        }

        try {
            // **The approved addresses, not just a yes** (S13b review, finding 5). `assert()`
            // resolves the host and approves what it finds; the HTTP client then resolves it again
            // when it connects, so a record with a one-second TTL can answer publicly for the check
            // and `169.254.169.254` for the request. Re-checking immediately before the call —
            // which is what this did — narrows that window to milliseconds without closing it.
            $ips = SafeUrl::approvedIps($safe);
        } catch (RuntimeException $e) {
            Log::channel('signals')->warning('Feed refused by the SSRF guard.', [
                'source' => $this->kind()->value,
                'reason' => $e->getMessage(),
            ]);

            // Misconfigured rather than unavailable: this will never succeed, so the run must not
            // retry it and the breaker must not open — the address is the problem, not the network.
            throw SignalsException::sourceMisconfigured($this->kind()->value);
        }

        // **A host we cannot resolve is one we cannot pin, so a feed refuses it.** `SafeUrl` treats
        // an unresolvable host as safe to attempt, which is right for a lead's website field: a
        // flaky lookup should not block a legitimate site, and the request just fails on connect.
        // A feed is different in kind — it is the one address in this product that a customer
        // supplies and we then fetch on a schedule with nobody watching — and "could not resolve"
        // is also what a host answering only inside a rebinding window looks like from here.
        if ($ips === []) {
            Log::channel('signals')->warning('Feed refused: its host does not resolve, so it cannot be pinned.', [
                'source' => $this->kind()->value,
            ]);

            throw SignalsException::sourceMisconfigured($this->kind()->value);
        }

        $headers = ['Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8'];

        foreach ($this->validators($scope) as $header => $value) {
            $headers[$header] = $value;
        }

        return $this->guarded(fn (): Response => $this->http
            ->withHeaders($headers)
            // **Pinned to the addresses the guard approved**, where curl is the handler. Null
            // (no curl) passes through as no option, leaving the pre-request check that was always
            // there; `Http::fake()` ignores it, which is why the guard's own tests assert the
            // refusals and not this line.
            ->withOptions(array_filter([
                'curl' => ($resolve = SafeUrl::resolveOption($safe, $ips)) === null
                    ? null
                    : [CURLOPT_RESOLVE => [$resolve]],
            ]))
            // **`stream` on, which the S13b review found missing.** Without it Guzzle buffers the
            // whole body into memory before `readCapped()` ever runs, so the byte cap was
            // describing a protection that did not exist: a member could point a feed at a
            // multi-gigabyte response and take the queue worker with it. `HttpWebContentFetcher`
            // has always set this; this source was written from its shape and missed the line.
            ->withOptions(['stream' => true])
            ->withUserAgent((string) config('signals.sources.feed.user_agent', 'AlimaProspects/1.0 (+signals)'))
            ->timeout((int) config('signals.sources.feed.timeout', 10))
            ->connectTimeout((int) config('signals.sources.feed.connect_timeout', 5))
            // **No redirects.** The guard validated one address; following a `Location` would hand
            // the client an address nobody checked, which is the standard way round an SSRF guard.
            ->withoutRedirecting()
            ->retry(
                (int) config('signals.sources.feed.retry_times', 1),
                (int) config('signals.sources.feed.retry_sleep_ms', 250),
                $this->retryWhen(),
                throw: false,
            )
            ->get($safe));
    }

    /**
     * A **404 or 410 on a feed is a statement about the address**, and it is permanent.
     *
     * Publishers move feeds. The address is a member's configuration, not a query we composed, so
     * the run must survive finding nothing at it — and it must not retry an address that has been
     * deleted.
     *
     * **This used to say `isQueryRejection()`, which the S13b review caught.** A feed is asked
     * exactly one query per definition (`SignalQueryBuilder::pushDefinitionQueries()`), so "refused
     * a query" and "refused *every* query" are the same event for it, and
     * `RunSignalWatchAction::assertSourcesAnswered()` therefore failed the whole run — news results
     * included, already paid for — every single time a publisher moved a feed. The rule it tripped
     * is right for an exhausted Serper account and wrong for a dead URL, which is why the third
     * outcome exists: see {@see SourceAddressNotFound}.
     */
    protected function isAddressNotFound(Response $response): bool
    {
        return in_array($response->status(), [404, 410], strict: true);
    }

    /**
     * Map a parsed feed's entries into unclassified source items.
     *
     * @return list<SourceItem>
     */
    private function mapEntries(FeedDocument $document, SignalSourceQuery $query): array
    {
        $ignore = [...$query->ignoreDomains, ...$this->kind()->ignoreDomains()];
        $max = max(1, (int) config('signals.sources.feed.max_items', 40));

        $items = [];
        $seen = [];

        foreach ($document->entries as $entry) {
            if (count($items) >= $max) {
                break;
            }

            $link = $this->normaliser->canonicalUrl($entry->link);
            $urlHash = $this->normaliser->urlHash($entry->link);

            if ($link === null || $urlHash === null || $entry->title === '') {
                continue;
            }

            // A team's own skip list still applies: a feed that syndicates a competitor's blog is
            // exactly the case this exists for. The feed's own publisher name is what the outlet
            // match compares against.
            if ($this->ignored->blocksItem($link, $document->title, $ignore)) {
                continue;
            }

            $item = new SourceItem(
                title: $entry->title,
                snippet: $entry->summary,
                link: $link,
                urlHash: $urlHash,
                // The **feed's** title, not the entry's: a feed is a publication, and that is what
                // "who published this" means here.
                source: $document->title,
                publishedAt: $entry->publishedAt,
                raw: [
                    'title' => $entry->title,
                    'summary' => $entry->summary,
                    'link' => $link,
                    'source' => $document->title,
                    'guid' => $entry->guid,
                ],
                contentHash: $this->normaliser->contentHash($entry->title, $document->title),
                dedupeHash: $this->normaliser->dedupeHash($link, $entry->title, $document->title),
                kind: $this->kind(),
                // **Null, deliberately.** A feed entry is prose somebody wrote, so it is classified
                // by the same prompt as a news article. Only an authoritative, already-parsed source
                // may assert its own event (ADR 0023 §6), and a newsletter is not one.
                classification: null,
            );

            if (isset($seen[$item->dedupeKey()])) {
                continue;
            }

            $seen[$item->dedupeKey()] = true;
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Read at most `max_bytes` off the streamed body.
     *
     * The same shape as `HttpWebContentFetcher::readCapped()` and for the same reason: a feed that
     * is secretly enormous must not be able to exhaust a queue worker's memory. Not shared with it,
     * because that class reads to produce prose and this one reads to produce XML — and the cap is
     * four lines, where the SSRF guard was the part worth having exactly one of.
     */
    private function readCapped(Response $response): string
    {
        $maxBytes = max(1_024, (int) config('signals.sources.feed.max_bytes', 2_000_000));
        $stream = $response->toPsrResponse()->getBody();

        $body = '';

        while (! $stream->eof() && strlen($body) < $maxBytes) {
            $chunk = $stream->read($maxBytes - strlen($body));

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        return $body;
    }

    /**
     * The feed URL a config names, normalised and SSRF-checked, or null when there is none we may
     * fetch.
     *
     * @param  array<array-key, mixed>  $config
     */
    private function feedUrl(array $config): ?string
    {
        $url = $this->configuredUrl($config);

        if ($url === null) {
            return null;
        }

        return SafeUrl::allows($url) ? SafeUrl::normalise($url) : null;
    }

    /**
     * The URL a config names, whether or not it is one we may fetch.
     *
     * Split from {@see self::feedUrl()} so `fetch()` can tell "no feed configured" from "a feed
     * configured at an address we refuse" — the first is nothing to do and the second is worth
     * reporting.
     *
     * @param  array<array-key, mixed>  $config
     */
    private function configuredUrl(array $config): ?string
    {
        $slice = $config['feed'] ?? $config;
        $url = is_array($slice) ? ($slice['url'] ?? null) : null;

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    /**
     * The cache scope for one team's polling of one feed, or null for a preview.
     *
     * **Per team, and that is a tenancy rule rather than a tuning choice** (S13b review, finding 4).
     * Both entries used to be keyed by `sha1($url)` alone, on the reasoning in the class docblock:
     * three watches pointed at one newsletter should be three readings of one fetch rather than
     * three requests to a small publisher. That reasoning holds **inside** a team and fails across
     * teams, because nothing caches the entries for the second reader to read — so team A's poll
     * stamped the floor and banked the ETag, and team B's fetch of the same public newsletter
     * returned an empty list or a 304 and their signals simply never existed. One tenant's
     * politeness is not payable with another tenant's results.
     *
     * The price is N requests an hour to a popular feed for the N teams watching it, each still
     * floored. The team id comes off the query's config, which
     * {@see SignalQueryBuilder::pushDefinitionQueries()} stamps; a query with no team on it (which
     * nothing emits) gets no stamping rather than a shared key, so the failure mode is an extra
     * fetch and never a crossed one.
     */
    private function cacheKey(SignalSourceQuery $query, string $url): ?string
    {
        if ($query->preview) {
            return null;
        }

        $teamId = $query->config['team_id'] ?? null;

        if (! is_int($teamId) && ! (is_string($teamId) && ctype_digit($teamId))) {
            return null;
        }

        return ((int) $teamId).':'.sha1($url);
    }

    /**
     * The conditional-GET headers for this feed's last fetch.
     *
     * @return array<string, string>
     */
    private function validators(?string $scope): array
    {
        if ($scope === null) {
            return [];
        }

        $stored = $this->cache->get(self::VALIDATOR_PREFIX.$scope);

        if (! is_array($stored)) {
            return [];
        }

        $headers = [];

        if (is_string($stored['etag'] ?? null) && $stored['etag'] !== '') {
            $headers['If-None-Match'] = $stored['etag'];
        }

        if (is_string($stored['last_modified'] ?? null) && $stored['last_modified'] !== '') {
            $headers['If-Modified-Since'] = $stored['last_modified'];
        }

        return $headers;
    }

    /**
     * Remember what the publisher gave us to send back next time.
     *
     * In the cache rather than on a row: these are a courtesy to the publisher and a bandwidth
     * saving, not data the product owns. Losing them costs one full fetch, which is why it is safe
     * for them to evaporate when the cache is cleared — the same reasoning the circuit breaker's own
     * note gives for being cache-backed and advisory.
     */
    private function rememberValidators(?string $scope, Response $response): void
    {
        if ($scope === null) {
            return;
        }

        $etag = (string) $response->header('ETag');
        $lastModified = (string) $response->header('Last-Modified');

        if ($etag === '' && $lastModified === '') {
            return;
        }

        $this->cache->put(
            self::VALIDATOR_PREFIX.$scope,
            ['etag' => $etag, 'last_modified' => $lastModified],
            now()->addDays(max(1, (int) config('signals.sources.feed.validator_ttl_days', 30))),
        );
    }

    /**
     * Whether this team fetched this feed too recently to fetch it again.
     *
     * Per **team and feed URL**: inside one team, three watches pointed at the same newsletter are
     * three readings of one fetch rather than three requests to a small publisher who would
     * reasonably block us for it. Across teams it cannot be, because the second reader has nothing
     * to read — see {@see self::cacheKey()}.
     */
    private function polledTooRecently(string $scope): bool
    {
        return $this->cache->has(self::POLLED_PREFIX.$scope);
    }

    private function rememberPoll(?string $scope): void
    {
        if ($scope === null) {
            return;
        }

        $minutes = max(1, (int) config('signals.sources.feed.min_poll_minutes', 60));

        $this->cache->put(self::POLLED_PREFIX.$scope, true, now()->addMinutes($minutes));
    }
}
