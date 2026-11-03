<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\LeadSourcing\Services\AbstractHttpLeadSourceProvider;
use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Exceptions\SignalsException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
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
            Log::channel('signals')->warning('Signal source connection failed.', [
                'source' => $source,
                'exception' => $e->getMessage(),
            ]);

            throw SignalsException::sourceUnavailable($source);
        } catch (Throwable $e) {
            // Anything else (a programming error, a thrown RequestException) is not a signal the
            // source is down — fail without opening the circuit for every team.
            Log::channel('signals')->error('Signal source request errored.', [
                'source' => $source,
                'exception' => $e->getMessage(),
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

        // A verdict about this query, not about the source. The breaker stays shut: a template with
        // a quote in it says nothing about whether Serper is up.
        if ($this->isQueryRejection($status)) {
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
     * Whether this status is the source telling us *that query* was unacceptable.
     *
     * 400 and 422 only. A 401/403 is a credential problem and a 404 is a wrong endpoint — both are
     * ours and both should fail the run loudly rather than be swallowed one query at a time, which
     * would turn a bad API key into a run that quietly finds nothing for ever.
     */
    protected function isQueryRejection(int $status): bool
    {
        return $status === 400 || $status === 422;
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
     * @return Closure(Throwable): bool
     */
    protected function retryWhen(): Closure
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
