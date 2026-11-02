<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A minimal cache-backed circuit breaker for external providers (CLAUDE.md: external calls use
 * timeouts, retries + backoff, and circuit-breaker guards). After {@see $threshold} consecutive
 * failures for a key the circuit "opens" for {@see $cooldownSeconds}, during which calls are
 * refused outright instead of hammering a struggling provider. A single success closes it again.
 *
 * State lives in the shared cache so it holds across queue workers. It is advisory, not a hard
 * guarantee — good enough to shed load and recover. Shared across modules (Lead Sourcing, Signals);
 * each module passes its own {@see $prefix} so their breaker state never shares a cache namespace.
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly Cache $cache,
        private readonly int $threshold = 5,
        private readonly int $cooldownSeconds = 60,
        private readonly string $prefix = 'cb',
    ) {}

    /**
     * Whether calls for this key are currently allowed (circuit closed).
     */
    public function allows(string $key): bool
    {
        return ! $this->cache->has($this->openKey($key));
    }

    /**
     * Whether the circuit is currently open — the inverse of {@see self::allows()}, named for the
     * reader rather than the caller. The admin panel's provider-health screen (Phase 19c) asks this
     * question; a guard asks the other one.
     */
    public function isOpen(string $key): bool
    {
        return ! $this->allows($key);
    }

    /**
     * Consecutive failures recorded for this key, as far as the cache still remembers them.
     *
     * Read-only, for the provider-health screen. It is **advisory**, like everything else here:
     * the counter has the cooldown's own TTL, so a quiet provider reports zero rather than its
     * history, and a cleared cache reports zero rather than refusing to answer.
     */
    public function failures(string $key): int
    {
        return (int) $this->cache->get($this->failuresKey($key), 0);
    }

    /**
     * Clear the failure count and close the circuit.
     *
     * The operator-facing name, and the one {@see self::recordSuccess()} is implemented in terms
     * of. An operator resetting a breaker after a provider recovers is not recording a success —
     * nothing succeeded — and a panel calling `recordSuccess()` would be writing a small lie into
     * the only place that knows how the provider is doing.
     */
    public function reset(string $key): void
    {
        $this->cache->forget($this->failuresKey($key));
        $this->cache->forget($this->openKey($key));
    }

    /**
     * Record a success: clear the failure count and close the circuit.
     */
    public function recordSuccess(string $key): void
    {
        $this->reset($key);
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
        return "{$this->prefix}:failures:{$key}";
    }

    private function openKey(string $key): string
    {
        return "{$this->prefix}:open:{$key}";
    }
}
