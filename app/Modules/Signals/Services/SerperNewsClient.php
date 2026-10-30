<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Signals\Contracts\NewsSearchClient;
use App\Modules\Signals\Data\NewsItem;
use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Exceptions\SignalsException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Calls Serper `/news` (ADR 0010/0011) and maps the `news` array to normalised, clamped
 * {@see NewsItem} DTOs. Reuses the Lead Sourcing hardening pattern (timeouts, retry on 429/5xx only,
 * a {@see CircuitBreaker} that trips only on transient failures, fail-fast on a missing key) and the
 * reusable {@see CircuitBreaker} itself — Signals already depends one-way on Lead Sourcing. Clamps
 * text, rejects non-http(s) links and drops `ignore_domains`. Never returns partial garbage: a
 * genuine failure raises a {@see SignalsException}.
 */
final class SerperNewsClient implements NewsSearchClient
{
    private const string BREAKER_KEY = 'signals_news';

    private const int MAX_TITLE = 255;

    private const int MAX_SNIPPET = 1000;

    private const int MAX_DATE = 64;

    // The `signals.source_url` column is string(2048); a longer link can't be stored, so drop it.
    private const int MAX_URL = 2048;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly CircuitBreaker $breaker,
        private readonly SignalItemNormaliser $normaliser,
    ) {}

    public function search(SignalQuery $query): array
    {
        $driver = (string) config('signals.serper.provider', 'serper');
        if ($driver !== 'serper') {
            throw SignalsException::newsProviderUnavailable();
        }

        $apiKey = $this->requireApiKey();
        $baseUrl = rtrim((string) config('signals.serper.base_url'), '/');

        $response = $this->guarded(fn (): Response => $this->http
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
            ->post('/news', [
                'q' => $query->q,
                'gl' => $query->gl,
                'hl' => $query->hl,
                'tbs' => $query->tbs,
                'num' => $query->num,
            ]));

        // A 200 with a body that isn't a JSON object, or a `news` value that isn't a list, is not
        // "no results" — it's an unexpected payload (an HTML error/auth page behind a 200). Fail
        // rather than silently returning nothing. A valid object with no `news` key is empty results.
        $payload = $response->json();
        if (! is_array($payload)) {
            Log::channel('signals')->warning('News provider returned a non-JSON body.', [
                'status' => $response->status(),
            ]);

            throw SignalsException::newsProviderFailed();
        }

        $news = $payload['news'] ?? [];
        if (! is_array($news)) {
            Log::channel('signals')->warning('News provider returned an unexpected payload shape.', [
                'status' => $response->status(),
            ]);

            throw SignalsException::newsProviderFailed();
        }

        // The global list (property portals, aggregators) plus this watch's own (S6) — a client's
        // competitors are only knowable by that client.
        /** @var list<string> $configured */
        $configured = (array) config('signals.ignore_domains', []);
        $ignore = array_values(array_unique([...$configured, ...$query->ignoreDomains]));

        $items = [];
        $seenHashes = [];

        foreach ($news as $result) {
            if (! is_array($result)) {
                continue;
            }

            $item = $this->mapItem($result);
            if (! $item instanceof NewsItem) {
                continue;
            }

            // Normalised through the same helper the dedupe hash uses, so a trailing-dot FQDN
            // (`bayut.com.`) or a tab-in-the-authority host (`bayut\t.com` → `bayut_.com`) can't
            // walk past a list the team thought excluded it. An unrecognisable host is dropped
            // outright rather than let through unchecked.
            $host = $this->normaliser->host((string) parse_url($item->link, PHP_URL_HOST));

            if ($host === null || $this->isIgnored($host, $ignore)) {
                continue;
            }

            // Collapse duplicate articles (same canonical URL) within one response.
            if (isset($seenHashes[$item->urlHash])) {
                continue;
            }
            $seenHashes[$item->urlHash] = true;

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Map one Serper `/news` result into a {@see NewsItem}, or null if it has no usable http(s) link
     * or no title.
     *
     * @param  array<array-key, mixed>  $result
     */
    private function mapItem(array $result): ?NewsItem
    {
        $link = is_string($result['link'] ?? null) ? trim($result['link']) : '';
        $title = is_string($result['title'] ?? null) ? trim($result['title']) : '';

        // Length and title first: parsing a multi-megabyte `link` only to throw it away is work a
        // hostile (or merely broken) provider response shouldn't be able to ask for.
        if ($title === '' || $link === '' || mb_strlen($link) > self::MAX_URL) {
            return null;
        }

        // L1: store the **canonical** URL, not the raw one. `parse_url` resolves userinfo
        // correctly, so `https://gulfnews.com@evil.ae/x` is checked against `evil.ae` above — but
        // the raw string would still be what we render, and a member reading the link would see a
        // publisher they trust. The canonical form has the userinfo stripped.
        $canonical = $this->normaliser->canonicalUrl($link);
        $urlHash = $this->normaliser->urlHash($link);

        if ($canonical === null || $urlHash === null) {
            return null;
        }

        $link = $canonical;

        $snippet = is_string($result['snippet'] ?? null) ? trim($result['snippet']) : '';
        $source = is_string($result['source'] ?? null) ? trim($result['source']) : '';
        $date = is_string($result['date'] ?? null) ? trim($result['date']) : '';

        // Everything stored on the item — including the `raw` payload persisted to signals.raw — is
        // length-clamped so a hostile or oversized item can't bloat storage.
        $title = Str::limit($title, self::MAX_TITLE, '');
        $snippet = Str::limit($snippet, self::MAX_SNIPPET, '');
        $source = $source === '' ? null : Str::limit($source, self::MAX_TITLE, '');
        $date = $date === '' ? null : Str::limit($date, self::MAX_DATE, '');

        return new NewsItem(
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
        );
    }

    /**
     * Parse Serper's best-effort date ("3 days ago", "Oct 25, 2025") to UTC, or null when it can't
     * be read — the caller falls back to the run time.
     */
    private function parseDate(string $date): ?Carbon
    {
        if ($date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether an already-normalised host (or any parent of it) is on the ignore list.
     *
     * The list's own entries are normalised here too — they come from config and from a team's
     * typing, so a trailing dot or a `www.` prefix on either side must not decide the outcome.
     *
     * @param  list<string>  $ignore
     */
    private function isIgnored(string $host, array $ignore): bool
    {
        foreach ($ignore as $blocked) {
            $normalised = Str::replaceStart('www.', '', rtrim(Str::lower(trim((string) $blocked)), '.'));

            if ($normalised === '') {
                continue;
            }

            if ($host === $normalised || Str::endsWith($host, '.'.$normalised)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the Serper API key, or throw a non-retryable config error so the run fails fast.
     */
    private function requireApiKey(): string
    {
        $apiKey = (string) config('signals.serper.api_key');

        if ($apiKey === '') {
            throw SignalsException::newsProviderMisconfigured();
        }

        return $apiKey;
    }

    /**
     * Execute the request behind the circuit breaker. A thrown error or a transient failure (5xx /
     * 429 / connection error) trips the breaker; any failure raises a {@see SignalsException}. Logs
     * to the `signals` channel — no query text or names, only status.
     *
     * @param  Closure(): Response  $request
     */
    private function guarded(Closure $request): Response
    {
        if (! $this->breaker->allows(self::BREAKER_KEY)) {
            throw SignalsException::newsProviderFailed();
        }

        try {
            $response = $request();
        } catch (ConnectionException $e) {
            $this->breaker->recordFailure(self::BREAKER_KEY);
            Log::channel('signals')->warning('News provider connection failed.', [
                'exception' => $e->getMessage(),
            ]);

            throw SignalsException::newsProviderFailed();
        } catch (Throwable $e) {
            Log::channel('signals')->error('News provider request errored.', [
                'exception' => $e->getMessage(),
            ]);

            throw SignalsException::newsProviderFailed();
        }

        if ($response->failed()) {
            // Only transient failures (5xx, 429) trip the breaker — a 4xx is our mistake and must not
            // open the circuit for every team.
            if ($response->serverError() || $response->status() === 429) {
                $this->breaker->recordFailure(self::BREAKER_KEY);
            }

            Log::channel('signals')->warning('News provider request failed.', [
                'status' => $response->status(),
            ]);

            throw SignalsException::newsProviderFailed();
        }

        $this->breaker->recordSuccess(self::BREAKER_KEY);

        return $response;
    }

    /**
     * Retry only on genuinely transient conditions — a connection error, a 5xx, or a 429.
     *
     * @return Closure(Throwable): bool
     */
    private function retryWhen(): Closure
    {
        return static function (Throwable $e): bool {
            if ($e instanceof ConnectionException) {
                return true;
            }

            if ($e instanceof RequestException && $e->response !== null) {
                return $e->response->serverError() || $e->response->status() === 429;
            }

            return false;
        };
    }
}
