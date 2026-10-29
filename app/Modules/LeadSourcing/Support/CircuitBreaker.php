<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Support;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A minimal cache-backed circuit breaker for external providers (CLAUDE.md: external calls use
 * timeouts, retries + backoff, and circuit-breaker guards). After {@see $threshold} consecutive
 * failures for a key the circuit "opens" for {@see $cooldownSeconds}, during which calls are
 * refused outright instead of hammering a struggling provider. A single success closes it again.
 *
 * State lives in the shared cache so it holds across queue workers. It is advisory, not a hard
 * guarantee — good enough to shed load and recover, which is all Phase 7 needs.
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly Cache $cache,
        private readonly int $threshold = 5,
        private readonly int $cooldownSeconds = 60,
    ) {}

    /**
     * Whether calls for this key are currently allowed (circuit closed).
     */
    public function allows(string $key): bool
    {
        return ! $this->cache->has($this->openKey($key));
    }

    /**
     * Record a success: clear the failure count and close the circuit.
     */
    public function recordSuccess(string $key): void
    {
        $this->cache->forget($this->failuresKey($key));
        $this->cache->forget($this->openKey($key));
    }

    /**
     * Record a failure: once consecutive failures reach the threshold, open the circuit for the
     * cooldown window.
     */
    public function recordFailure(string $key): void
    {
        $failures = (int) $this->cache->get($this->failuresKey($key), 0) + 1;
        $this->cache->put($this->failuresKey($key), $failures, $this->cooldownSeconds);

        if ($failures >= $this->threshold) {
            $this->cache->put($this->openKey($key), true, $this->cooldownSeconds);
        }
    }

    private function failuresKey(string $key): string
    {
        return "lead_sourcing:cb:failures:{$key}";
    }

    private function openKey(string $key): string
    {
        return "lead_sourcing:cb:open:{$key}";
    }
}
