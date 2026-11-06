<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Support\IgnoredDomains;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Serper half of the source hardening (S13, ADR 0023): one POST, one payload shape, one mapping
 * and clamping pass, shared by {@see NewsSignalSource} (`/news`) and {@see WebSearchSignalSource}
 * (`/search`).
 *
 * **Why a second abstract layer.** {@see AbstractHttpSignalSourceProvider} is the generic hardening
 * every HTTP source inherits, including S13b's Companies House, which is a different vendor with a
 * different credential and no `q` at all. What the two Serper endpoints share is everything *else* —
 * the key, the base URL, the `gl`/`hl`/`tbs`/`num` payload, the text clamps, the canonical-URL
 * identities, the skip list and the within-response de-duplication — and having that in one place is
 * what makes "web search behaves like news except for its noise profile" true rather than aspirational.
 * Two copies of the clamping would be two subtly different clamps.
 *
 * Subclasses supply three things: the endpoint, the key its results arrive under, and how to read an
 * outlet name out of one result — which is the real difference between the two, because `/search`
 * has no `source` field at all.
 */
abstract class AbstractSerperSignalSource extends AbstractHttpSignalSourceProvider
{
    protected const int MAX_TITLE = 255;

    protected const int MAX_SNIPPET = 1000;

    protected const int MAX_DATE = 64;

    // The `signals.source_url` column is string(2048); a longer link can't be stored, so drop it.
    protected const int MAX_URL = 2048;

    public function __construct(
        HttpFactory $http,
        CircuitBreaker $breaker,
        protected readonly SignalItemNormaliser $normaliser,
        protected readonly IgnoredDomains $ignored,
    ) {
        parent::__construct($http, $breaker);
    }

    /** The Serper path this source posts to, e.g. `/news`. */
    abstract protected function endpoint(): string;

    /** The response key its results arrive under, e.g. `news`. */
    abstract protected function resultsKey(): string;

    /**
     * The publisher of one result, or null when it cannot be read.
     *
     * `/news` carries a human outlet name; `/search` carries none and the host is the honest
     * substitute. Both feed the same two places — the skip list (which matches an outlet's name as
     * well as a host, since S11) and the content identity.
     *
     * @param  array<array-key, mixed>  $result
     */
    abstract protected function outletFor(array $result, string $link): ?string;

    /**
     * Every Serper-backed source serves any definition that has at least one template to run.
     *
     * Templates are the definition's own data and the builder is what expands them, so there is
     * nothing for a search source to decline on: a definition with no templates produces no queries
     * before it ever reaches a provider.
     */
    public function supports(SignalDefinition $definition): bool
    {
        return true;
    }

    public function fetch(SignalSourceQuery $query): array
    {
        $driver = (string) config('signals.serper.provider', 'serper');

        if ($driver !== 'serper') {
            throw SignalsException::sourceDriverUnsupported($this->kind()->value);
        }

        $response = $this->request($query);

        // A 200 with a body that isn't a JSON object, or a results value that isn't a list, is not
        // "no results" — it's an unexpected payload (an HTML error/auth page behind a 200). Fail
        // rather than silently returning nothing. A valid object with no results key is empty results.
        $payload = $response->json();

        if (! is_array($payload)) {
            Log::channel('signals')->warning('Signal source returned a non-JSON body.', [
                'source' => $this->kind()->value,
                'status' => $response->status(),
            ]);

            throw SignalsException::sourceFailed($this->kind()->value);
        }

        $results = $payload[$this->resultsKey()] ?? [];

        if (! is_array($results)) {
            Log::channel('signals')->warning('Signal source returned an unexpected payload shape.', [
                'source' => $this->kind()->value,
                'status' => $response->status(),
            ]);

            throw SignalsException::sourceFailed($this->kind()->value);
        }

        return $this->mapResults($results, $query);
    }

    /**
     * The guarded POST. Every Serper parameter comes off the query, which is what keeps a provider
     * from needing to know which team asked.
     */
    private function request(SignalSourceQuery $query): Response
    {
        $apiKey = $this->requireApiKey('signals.serper.api_key');
        $baseUrl = rtrim((string) config('signals.serper.base_url'), '/');

        return $this->guarded(fn (): Response => $this->http
            ->baseUrl($baseUrl)
            ->withHeaders(['X-API-KEY' => $apiKey])
            ->timeout((int) config('signals.serper.timeout', 10))
            ->connectTimeout((int) config('signals.serper.connect_timeout', 5))
            ->retry(
                (int) config('signals.serper.retry_times', 2),
                (int) config('signals.serper.retry_sleep_ms', 250),
                $this->retryWhen(),
                throw: false,
            )
            ->acceptJson()
            ->post($this->endpoint(), array_filter([
                'q' => $query->q,
                // Omitted entirely when the region is `global` (S8): `gl` is nullable there because
                // "anywhere" is not a country, and sending an invented code for it would bias the
                // one search whose point is not to be biased. `array_filter` drops only the null.
                'gl' => $query->gl,
                'hl' => $query->hl,
                'tbs' => $query->tbs,
                'num' => $query->num,
            ], static fn (mixed $value): bool => $value !== null)));
    }

    /**
     * Map, filter and de-duplicate one response's results.
     *
     * @param  array<array-key, mixed>  $results
     * @return list<SourceItem>
     */
    private function mapResults(array $results, SignalSourceQuery $query): array
    {
        // The team's list plus **this source's own** (S13). A source's defaults are merged here
        // rather than carried on the query because a team's list applies to every source and a
        // source's list applies to every team — see `SignalSourceQuery`.
        $ignore = [...$query->ignoreDomains, ...$this->kind()->ignoreDomains()];

        $items = [];
        $seenHashes = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $item = $this->mapItem($result);

            if (! $item instanceof SourceItem) {
                continue;
            }

            // The global list (property portals, aggregators), this source's own, and this watch's
            // (S6) — a client's competitors are only knowable by that client. Asked of
            // `IgnoredDomains` rather than tested here, because S9's preview has to label what this
            // drops and the two must not disagree by a hair of normalisation. An unreadable host
            // counts as blocked: a link whose authority cannot be parsed is not one we can vouch for.
            //
            // **By link or by outlet name since S11.** A redirected item's host is `google.com`, so
            // a host-only list could not express "not this publisher" about the very items most in
            // need of it.
            if ($this->ignored->blocksItem($item->link, $item->source, $ignore)) {
                continue;
            }

            // Collapse duplicates within one response — by the dedupe identity, so a redirector
            // that hands back the same item under two tokens is still one item.
            if (isset($seenHashes[$item->dedupeKey()])) {
                continue;
            }

            $seenHashes[$item->dedupeKey()] = true;

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Map one Serper result into a {@see SourceItem}, or null if it has no usable http(s) link or no
     * title.
     *
     * @param  array<array-key, mixed>  $result
     */
    protected function mapItem(array $result): ?SourceItem
    {
        $link = is_string($result['link'] ?? null) ? trim($result['link']) : '';
        $title = is_string($result['title'] ?? null) ? trim($result['title']) : '';

        // Length and title first: parsing a multi-megabyte `link` only to throw it away is work a
        // hostile (or merely broken) provider response shouldn't be able to ask for.
        if ($title === '' || $link === '' || mb_strlen($link) > self::MAX_URL) {
            return null;
        }

        // L1: store the **canonical** URL, not the raw one. `parse_url` resolves userinfo correctly,
        // so `https://gulfnews.com@evil.ae/x` is checked against `evil.ae` above — but the raw
        // string would still be what we render, and a member reading the link would see a publisher
        // they trust. The canonical form has the userinfo stripped.
        $canonical = $this->normaliser->canonicalUrl($link);
        $urlHash = $this->normaliser->urlHash($link);

        if ($canonical === null || $urlHash === null) {
            return null;
        }

        $link = $canonical;

        $snippet = is_string($result['snippet'] ?? null) ? trim($result['snippet']) : '';
        $date = is_string($result['date'] ?? null) ? trim($result['date']) : '';

        // Everything stored on the item — including the `raw` payload persisted to signals.raw — is
        // length-clamped so a hostile or oversized item can't bloat storage.
        $title = Str::limit($title, self::MAX_TITLE, '');
        $snippet = Str::limit($snippet, self::MAX_SNIPPET, '');
        $date = $date === '' ? null : Str::limit($date, self::MAX_DATE, '');

        $source = $this->outletFor($result, $link);
        $source = $source === null || $source === '' ? null : Str::limit($source, self::MAX_TITLE, '');

        return new SourceItem(
            title: $title,
            snippet: $snippet,
            link: $link,
            urlHash: $urlHash,
            source: $source,
            publishedAt: $this->parseDate((string) $date),
            raw: [
                'title' => $title,
                'snippet' => $snippet,
                'link' => $link,
                'source' => $source,
                'date' => $date,
            ],
            // S11: the content identity, and the identity this item actually dedupes on. Computed
            // from the **clamped** title and source, so the hash is derived from what gets stored
            // rather than from a payload that was truncated on its way to the row.
            contentHash: $this->normaliser->contentHash($title, $source),
            dedupeHash: $this->normaliser->dedupeHash($link, $title, $source),
            kind: $this->kind(),
        );
    }

    /**
     * Parse Serper's best-effort date ("3 days ago", "Oct 25, 2025") to UTC, or null when it can't
     * be read — the caller falls back to the run time.
     *
     * **Sanity-bounded since S13, and the spike is why.** On `/search` the `date` field is scraped
     * off the page rather than supplied by a news desk, and it comes back holding things that are
     * not dates at all: one result's `date` was the literal string "Exciting news". `Carbon::parse`
     * throws on most such junk and is alarmingly willing about the rest, so anything resolving
     * outside a plausible window is treated as unreadable. The window is generous on purpose — this
     * is a guard against nonsense, not a recency filter, which is `tbs`'s job.
     */
    protected function parseDate(string $date): ?Carbon
    {
        if ($date === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($date)->utc();
        } catch (Throwable) {
            return null;
        }

        // Two days of slack for a timezone the provider did not declare; a quarter of a century
        // back, because an archive piece resurfacing is real and a 1970 epoch default is not.
        if ($parsed->isAfter(now()->addDays(2)) || $parsed->isBefore(now()->subYears(25))) {
            return null;
        }

        return $parsed;
    }
}
