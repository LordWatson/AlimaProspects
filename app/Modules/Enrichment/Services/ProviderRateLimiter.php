<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Enrichment\Contracts\EnrichmentProvider;
use Illuminate\Cache\RateLimiter;

/**
 * Our own brake on how fast we ask a vendor (PL3).
 *
 * **It exists because hitting a vendor's published limit is a ban rather than a queue.** Several
 * enrichment vendors answer a burst with a temporary block on the whole account, which turns one
 * team's bulk enrichment into an outage for every other team. The limit here is deliberately far
 * below any vendor's own: it is not an attempt to use all of their allowance, it is an attempt
 * never to find out what happens at the edge of it.
 *
 * **Per provider and not per team.** The limit a vendor enforces is on our account, so sharing it
 * is the honest model — and a per-team limiter would let twenty teams each stay politely under a
 * limit and collectively sail past it. The unfairness that creates (one team's bulk run eating the
 * minute) is PL3's batch size and PL4's allowances to solve, not this class's.
 *
 * Backed by Laravel's own {@see RateLimiter}, so it is the cache driver's problem to be atomic —
 * Redis in production, array in tests. A limiter that silently became per-process would be worse
 * than none, which is why nothing here counts in PHP.
 */
final class ProviderRateLimiter
{
    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * Whether we may call this provider right now, consuming one of its allowance if so.
     *
     * Returns false rather than throwing or sleeping: the waterfall's answer to a rate-limited
     * provider is to **skip it and try the next one**, which is faster for the member and costs
     * nothing. Sleeping inside a queued job would hold a worker for a minute to save a call that
     * the next provider could have answered immediately.
     */
    public function attempt(EnrichmentProvider $provider): bool
    {
        $limit = $this->limitFor($provider);

        if ($limit <= 0) {
            return true; // no limit configured for this provider
        }

        $key = $this->key($provider);

        if ($this->limiter->tooManyAttempts($key, $limit)) {
            return false;
        }

        $this->limiter->hit($key, 60);

        return true;
    }

    /**
     * Calls left in the current minute, for the admin provider panel.
     */
    public function remaining(EnrichmentProvider $provider): ?int
    {
        $limit = $this->limitFor($provider);

        return $limit <= 0 ? null : $this->limiter->remaining($this->key($provider), $limit);
    }

    /**
     * Calls per minute this provider is allowed. Zero or absent means unlimited by us — the
     * vendor's own limit still applies, and a provider with no figure here is one nobody has asked
     * the question about.
     */
    private function limitFor(EnrichmentProvider $provider): int
    {
        return max(0, (int) config('enrichment.providers.'.$provider->key().'.rate_limit', 0));
    }

    private function key(EnrichmentProvider $provider): string
    {
        return 'enrichment:rate:'.$provider->key();
    }
}
