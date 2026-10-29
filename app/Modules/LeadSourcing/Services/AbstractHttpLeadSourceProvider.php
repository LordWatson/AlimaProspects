<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\LeadSourcing\Contracts\LeadSourceProvider;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Support\CircuitBreaker;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared hardening for HTTP-backed lead providers (CLAUDE.md external-call rule): a {@see CircuitBreaker}
 * keyed per provider, trips only on *transient* failures (5xx / 429 / connection errors) so a 4xx
 * config/query mistake can't open the circuit for every team, and normalises any provider failure to
 * a {@see LeadSourcingException}. Concrete providers implement {@see self::source()} and build their
 * request + mapping on top of {@see self::guarded()}.
 */
abstract class AbstractHttpLeadSourceProvider implements LeadSourceProvider
{
    public function __construct(
        protected readonly HttpFactory $http,
        protected readonly CircuitBreaker $breaker,
    ) {}

    /**
     * Execute a provider request behind the circuit breaker. The closure returns the HTTP response;
     * a thrown error or a transient failure trips the breaker, and any failure (transient or not)
     * raises a domain exception. A success closes the breaker and returns the response.
     *
     * @param  Closure(): Response  $request
     */
    protected function guarded(Closure $request): Response
    {
        $key = $this->source()->value;

        if (! $this->breaker->allows($key)) {
            throw LeadSourcingException::providerFailed($key);
        }

        try {
            $response = $request();
        } catch (ConnectionException $e) {
            // A genuine network failure is transient — trip the breaker.
            $this->breaker->recordFailure($key);
            Log::channel('lead_sourcing')->warning('Lead provider connection failed.', [
                'source' => $key,
                'exception' => $e->getMessage(),
            ]);

            throw LeadSourcingException::providerFailed($key);
        } catch (Throwable $e) {
            // Anything else (a programming error, a thrown RequestException from a bad response) is
            // not a signal the provider is down — fail the run without opening the circuit for all teams.
            Log::channel('lead_sourcing')->error('Lead provider request errored.', [
                'source' => $key,
                'exception' => $e->getMessage(),
            ]);

            throw LeadSourcingException::providerFailed($key);
        }

        if ($response->failed()) {
            // Only transient failures (5xx, rate-limit) trip the breaker — a 4xx (bad key/query) is
            // our fault and must not open the circuit for every team.
            if ($response->serverError() || $response->status() === 429) {
                $this->breaker->recordFailure($key);
            }

            Log::channel('lead_sourcing')->warning('Lead provider request failed.', [
                'source' => $key,
                'status' => $response->status(),
            ]);

            throw LeadSourcingException::providerFailed($key);
        }

        $this->breaker->recordSuccess($key);

        return $response;
    }

    /**
     * Read the provider's API key from config, or throw a **non-retryable** config error so the job
     * fails fast instead of burning its retries on a request that can never succeed.
     */
    protected function requireApiKey(string $configKey): string
    {
        $apiKey = (string) config($configKey);

        if ($apiKey === '') {
            throw LeadSourcingException::providerMisconfigured($this->source()->value);
        }

        return $apiKey;
    }

    /**
     * Retry only on genuinely transient conditions — a connection error, a 5xx, or a 429. A 4xx
     * (bad key/query) is our fault and retrying it just wastes time and can double-bill a paid
     * provider. Shared by every provider's `->retry(..., when: $this->retryWhen())`.
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
