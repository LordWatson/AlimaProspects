<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\LeadSourcing\Services\AbstractHttpLeadSourceProvider;
use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Exceptions\SourceAddressNotFound;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared hardening for HTTP-backed signal sources (S13, ADR 0023 §3) — the lineage of
 * {@see AbstractHttpLeadSourceProvider}, and the same CLAUDE.md external-call rule: a
 * {@see CircuitBreaker} keyed **per source**, retry on transient failures only, fail-fast on a
 * missing credential, and every provider failure normalised to a {@see SignalsException}.
 *
 * **Per-source breaker keys matter more here than they did for leads.** A team running news *and*
 * web search should not lose their news because Serper's `/search` is unwell; the key is
 * `signals_<kind>` under the `signals:cb` prefix, and each one is declared in
 * `Admin\Enums\PlatformProvider` so an open breaker is visible to operations (task 8) rather than
 * merely true.
 *
 * **Three outcomes, not two**, and the third is the one S13 added (ADR 0023 §4):
 *
 * 1. **Transient** — a 5xx, a 429, a connection error, an open breaker. Trips the breaker where it
 *    is the provider's health that is in question, and raises a **retryable**
 *    {@see SignalsException::sourceUnavailable()} so the queued run retries with backoff.
 * 2. **Ours** — a 401/403, a malformed 200, a missing key. Fails the run without opening the
 *    circuit for every team, because a 4xx is our mistake and not a signal the provider is down.
 * 3. **A verdict about this one query** — a 400 or 422, which is how Serper answers a `site:` or
 *    quoted-phrase pattern on a free account tier. Raises
 *    {@see SignalsException::sourceQueryRejected()}: not retryable, no breaker trip, and caught
 *    *per query* by the run so one template a member typed with a quote in it does not fail — and
 *    re-bill — their whole watch. The spike that found this is in `docs/modules/signals.md`.
 */
abstract class AbstractHttpSignalSourceProvider implements SignalSourceProvider
{
    /** The cache prefix every Signals breaker shares; `PlatformProvider::breakerPrefix()` mirrors it. */
    private const string BREAKER_PREFIX = 'signals';

    public function __construct(
        protected readonly HttpFactory $http,
        protected readonly CircuitBreaker $breaker,
    ) {}

    /**
     * The breaker key for this source.
     *
     * `signals_news` is spelled out rather than derived so the one key that existed before S13 keeps
     * its exact value: a rename would have silently abandoned whatever state was in the cache and,
     * worse, broken the health screen's `PlatformProvider::SerperNews` mapping, which is asserted
     * against this module.
     */
    protected function breakerKey(): string
    {
        return self::BREAKER_PREFIX.'_'.$this->kind()->value;
    }

    public function estimatedCostMicros(int $queries): int
    {
        return max(0, $queries) * $this->kind()->costMicrosPerRequest();
    }

    /**
     * Execute a source request behind the circuit breaker. The closure returns the HTTP response.
     *
     * @param  Closure(): Response  $request
     */
    protected function guarded(Closure $request): Response
    {
        $key = $this->breakerKey();
        $source = $this->kind()->value;

        if (! $this->breaker->allows($key)) {
            throw SignalsException::sourceUnavailable($source);
        }

        try {
            $response = $request();
        } catch (ConnectionException $e) {
            // A genuine network failure is transient — trip the breaker.
            $this->breaker->recordFailure($key);
            // **The class, never the message** (CLAUDE.md §2; the same discipline
            // `RunSignalWatchJob::failed()` spells out). A feed's address is supplied by the
            // customer and often carries a token, and a cURL connect or timeout message embeds the
            // whole URL — so the message is the one part of this that cannot be logged. The source
            // and the breaker state are what an operator actually needs here; the address is
            // recoverable from the definition.
            Log::channel('signals')->warning('Signal source connection failed.', [
                'source' => $source,
                'exception' => $e::class,
            ]);

            throw SignalsException::sourceUnavailable($source);
        } catch (Throwable $e) {
            // Anything else (a programming error, a thrown RequestException) is not a signal the
            // source is down — fail without opening the circuit for every team.
            // Likewise, and more so: a thrown `RequestException` carries the **response body** in
            // its message.
            Log::channel('signals')->error('Signal source request errored.', [
                'source' => $source,
                'exception' => $e::class,
            ]);

            throw SignalsException::sourceFailed($source);
        }

        if ($response->failed()) {
            return $this->fail($response);
        }

        $this->breaker->recordSuccess($key);

        return $response;
    }

    /**
     * Turn a failed response into the right one of the three outcomes.
     *
     * Logs status only — never the query text, never a name. A rejected pattern is logged at `info`
     * rather than `warning` on purpose: it is an ordinary consequence of a member's template and the
     * run reports it on the screen they can act on, so it is not something to wake anybody about.
     */
    private function fail(Response $response): never
    {
        $source = $this->kind()->value;
        $status = $response->status();

        // **Asked first, because it is the narrowest of the three** (S13b review). A verdict about
        // the *addressee* rather than about our query or the provider's health: an unknown company
        // number, a deleted feed. Logged at `info` for the same reason a rejection is — it is an
        // ordinary state of a long-lived configuration, not something to wake anybody about.
        if ($this->isAddressNotFound($response)) {
            Log::channel('signals')->info('Signal source has nothing at an address; query skipped.', [
                'source' => $source,
                'status' => $status,
            ]);

            throw SignalsException::sourceAddressNotFound($source);
        }

        // A verdict about this query, not about the source. The breaker stays shut: a template with
        // a quote in it says nothing about whether Serper is up.
        if ($this->isQueryRejection($response)) {
            Log::channel('signals')->info('Signal source rejected a query pattern; query skipped.', [
                'source' => $source,
                'status' => $status,
            ]);

            throw SignalsException::sourceQueryRejected($source);
        }

        // Only transient failures (5xx, rate-limit) trip the breaker.
        if ($response->serverError() || $status === 429) {
            $this->breaker->recordFailure($this->breakerKey());

            Log::channel('signals')->warning('Signal source request failed.', [
                'source' => $source,
                'status' => $status,
            ]);

            throw SignalsException::sourceUnavailable($source);
        }

        Log::channel('signals')->warning('Signal source refused a request.', [
            'source' => $source,
            'status' => $status,
        ]);

        throw SignalsException::sourceFailed($source);
    }

    /**
     * Whether this response is the source telling us **the thing we addressed is not there**.
     *
     * False by default, and deliberately so: for a source addressed by a search string we composed,
     * a 404 is a wrong endpoint — ours, and worth failing loudly. It is only a statement about an
     * addressee for the two S13b shapes that *have* one, which is why those two override it:
     * `CompaniesHouseSignalSource` (a company number off an unvalidated `leads.company_number`, or
     * one since struck off) and `FeedSignalSource` (an address a member typed, at a publisher who
     * has since moved it).
     *
     * See {@see SourceAddressNotFound} for why this is a third
     * outcome rather than either of the two ADR 0023 §4 named.
     */
    protected function isAddressNotFound(Response $response): bool
    {
        return false;
    }

    /**
     * Whether this response is the source telling us *that query* was unacceptable.
     *
     * A 401/403 is a credential problem and a 404 is a wrong endpoint — both are ours and both fail
     * the run loudly rather than being swallowed one query at a time, which would turn a bad API
     * key into a run that quietly finds nothing for ever.
     *
     * **The status is not enough, which is the S13a review's first finding.** This used to return
     * true for any 400 or 422, and a 400 is the most overloaded code a search API has: Serper
     * answers a refused query *pattern* with one, and from the same endpoint a 400 is also how an
     * exhausted account, a malformed `tbs`/`gl`/`num` of ours, and a changed API contract arrive.
     * Swallowing all of those per query would complete every run green, with nothing found, no
     * retry and the breaker deliberately shut — for ever, silently. That is precisely the failure
     * this module keeps legislating against.
     *
     * So the **body** has to say so. The markers live in
     * `signals.serper.query_rejection_markers` so a provider's wording can change without a deploy,
     * and anything that does not match is `sourceFailed()`: non-retryable, loud, and visible on the
     * run. Erring that way round is deliberate — a misclassified rejection costs one noisy failed
     * run, and a misclassified outage costs a customer their whole feature without telling anybody.
     */
    protected function isQueryRejection(Response $response): bool
    {
        if ($response->status() !== 400 && $response->status() !== 422) {
            return false;
        }

        $markers = config('signals.serper.query_rejection_markers', []);

        if (! is_array($markers) || $markers === []) {
            return false;
        }

        // The whole body rather than a named field: a provider that returns `{"message": …}` today
        // may return `{"error": {"message": …}}` tomorrow, and the question being asked — does this
        // response say the query was unacceptable — is the same either way. Lower-cased, because a
        // marker is matched and not parsed.
        $body = Str::lower($response->body());

        foreach ($markers as $marker) {
            if (is_string($marker) && $marker !== '' && Str::contains($body, Str::lower($marker))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read a credential from config, or throw a **non-retryable** config error so the job fails fast
     * instead of burning its retries on a request that can never succeed.
     */
    protected function requireApiKey(string $configKey): string
    {
        $apiKey = (string) config($configKey);

        if ($apiKey === '') {
            throw SignalsException::sourceMisconfigured($this->kind()->value);
        }

        return $apiKey;
    }

    /**
     * Retry only on genuinely transient conditions — a connection error, a 5xx, or a 429.
     *
     * A 4xx is our fault; retrying it wastes time and can double-bill a paid provider.
     *
     * **The parameter is nullable, and that is a bug fix rather than defensiveness** (S13b).
     * Laravel gates this callback on `Response::successful()` — 200–299 — rather than on
     * `failed()`, so a **3xx** response reaches it too, and hands it `$response->toException()`,
     * which is `null` for a status that is unsuccessful without having failed. Typed `Throwable`,
     * that was a `TypeError` escaping the retry helper, surfacing in `guarded()`'s catch-all as a
     * generic source failure.
     *
     * No source could reach it before: Serper POSTs return 200, 4xx or 5xx. The RSS source can,
     * because a conditional GET's whole purpose is to answer **304 Not Modified** — so the one
     * source that uses HTTP caching properly was the one that found this.
     *
     * Null means "the response did not fail", which is nothing to retry.
     *
     * @return Closure(Throwable|null): bool
     */
    protected function retryWhen(): Closure
    {
        return static function (?Throwable $e): bool {
            if ($e === null) {
                return false;
            }

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
