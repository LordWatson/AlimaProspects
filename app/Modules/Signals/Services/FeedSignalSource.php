<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Core\Support\Security\SafeUrl;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Exceptions\SignalsException;
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
 * 2. **A conditional GET.** The `ETag` and `Last-Modified` of the last fetch are cached per feed
 *    URL, so a feed that has not changed answers `304` with no body — which is most polls, and the
 *    difference between being a good citizen of somebody else's bandwidth and being blocked by them.
 * 3. **A byte cap**, read off a streamed body, so a feed that is secretly a 2GB file cannot exhaust
 *    a queue worker.
 * 4. **A poll-interval floor.** A watch may run hourly; a feed is not re-fetched more often than
 *    `signals.sources.feed.min_poll_minutes` whatever the cadence, because the cost of being
 *    impolite to a small publisher is being banned.
 * 5. **A per-team feed count**, enforced on the save path, because every feed is a standing
 *    outbound request from our infrastructure to an address a customer chose.
 */
final class FeedSignalSource extends AbstractHttpSignalSourceProvider
{
    /** Cache key prefix for the conditional-GET validators, per feed URL. */
    private const string VALIDATOR_PREFIX = 'signals:feed:validators:';

    /** Cache key prefix for the poll-interval floor, per feed URL. */
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

        if ($this->polledTooRecently($url)) {
            Log::channel('signals')->info('Feed skipped: inside the poll-interval floor.', [
                'source' => $this->kind()->value,
                'host' => $this->ignored->hostOf($url),
            ]);

            return [];
        }

        $response = $this->conditionalGet($url);

        // Nothing has changed since the last poll. Not an error and not an empty feed — the
        // publisher is telling us we already have it, which is the whole point of sending the
        // validators.
        if ($response->status() === 304) {
            $this->rememberPoll($url);

            return [];
        }

        $body = $this->readCapped($response);

        $this->rememberValidators($url, $response);
        $this->rememberPoll($url);

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
    private function conditionalGet(string $url): Response
    {
        $safe = SafeUrl::normalise($url);

        if ($safe === null) {
            throw SignalsException::sourceMisconfigured($this->kind()->value);
        }

        try {
            SafeUrl::assert($safe);
        } catch (RuntimeException $e) {
            Log::channel('signals')->warning('Feed refused by the SSRF guard.', [
                'source' => $this->kind()->value,
                'reason' => $e->getMessage(),
            ]);

            // Misconfigured rather than unavailable: this will never succeed, so the run must not
            // retry it and the breaker must not open — the address is the problem, not the network.
            throw SignalsException::sourceMisconfigured($this->kind()->value);
        }

        $headers = ['Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8'];

        foreach ($this->validators($safe) as $header => $value) {
            $headers[$header] = $value;
        }

        return $this->guarded(fn (): Response => $this->http
            ->withHeaders($headers)
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
     * A 304 is a success, not a failure, and the base guard has to be told so.
     *
     * `Response::failed()` is true for any status ≥ 400, so a 304 reaches
     * {@see AbstractHttpSignalSourceProvider::guarded()} as a success already — this override exists
     * for the opposite reason: a **404 or 410 on a feed is permanent**, and treating it as a generic
     * failure would have the run retry an address that has been deleted.
     */
    protected function isQueryRejection(Response $response): bool
    {
        if (in_array($response->status(), [404, 410], strict: true)) {
            return true;
        }

        return parent::isQueryRejection($response);
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
     * The conditional-GET headers for this feed's last fetch.
     *
     * @return array<string, string>
     */
    private function validators(string $url): array
    {
        $stored = $this->cache->get(self::VALIDATOR_PREFIX.sha1($url));

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
    private function rememberValidators(string $url, Response $response): void
    {
        $etag = (string) $response->header('ETag');
        $lastModified = (string) $response->header('Last-Modified');

        if ($etag === '' && $lastModified === '') {
            return;
        }

        $this->cache->put(
            self::VALIDATOR_PREFIX.sha1($url),
            ['etag' => $etag, 'last_modified' => $lastModified],
            now()->addDays(max(1, (int) config('signals.sources.feed.validator_ttl_days', 30))),
        );
    }

    /**
     * Whether this feed was fetched too recently to fetch again.
     *
     * The floor is per **feed URL** and not per watch, which is the point: three watches pointed at
     * the same newsletter should be three readings of one fetch, not three requests to a small
     * publisher who will reasonably block us for it.
     */
    private function polledTooRecently(string $url): bool
    {
        return $this->cache->has(self::POLLED_PREFIX.sha1($url));
    }

    private function rememberPoll(string $url): void
    {
        $minutes = max(1, (int) config('signals.sources.feed.min_poll_minutes', 60));

        $this->cache->put(self::POLLED_PREFIX.sha1($url), true, now()->addMinutes($minutes));
    }
}
