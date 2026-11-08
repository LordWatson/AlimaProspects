<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Providers;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Enrichment\Contracts\DnsResolver;
use App\Modules\Enrichment\Listeners\EnrichDiscoveredLeads;
use App\Modules\Enrichment\Services\EnrichmentProviderRegistry;
use App\Modules\Enrichment\Services\SystemDnsResolver;
use App\Modules\Enrichment\Services\ZeroBounceEmailVerifier;
use App\Modules\LeadSourcing\Events\DiscoveryRunCompleted;
use App\Modules\ModuleServiceProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;

/**
 * Enrichment & Data Quality module provider (Phase 8). Binds the {@see DnsResolver} seam used by the
 * free-tier email check (swapped for a fake in tests), and wires the listener that fans out
 * enrichment when a discovery run completes. The shared web fetcher it uses is bound in Core.
 *
 * PL2 adds the paid-vendor registry. Two things about how it is wired:
 *
 * - **Its own circuit breaker**, under the `enrichment:cb` prefix rather than Lead Sourcing's.
 *   A vendor outage and a Companies House outage are not the same event, and a shared breaker
 *   would make one close the other's door (ADR 0023's per-source rule).
 * - **Every provider registers regardless of whether it is configured.** The registry asks
 *   `isEnabled()` when it builds a waterfall, so an unconfigured vendor is absent from the
 *   waterfall rather than throwing inside it — and it still appears in the admin panel's list of
 *   providers, which is where somebody looks to find out *why* nothing is being found.
 */
final class EnrichmentServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);

        // Vendor HTTP's own breaker, keyed apart from every other module's.
        $this->app->singleton('enrichment.breaker', fn ($app): CircuitBreaker => new CircuitBreaker(
            $app->make(Cache::class),
            (int) config('enrichment.circuit_breaker.threshold', 5),
            (int) config('enrichment.circuit_breaker.cooldown', 60),
            prefix: 'enrichment:cb',
        ));

        // Bound explicitly, **not** left to autowiring: `CircuitBreaker::class` is Lead Sourcing's
        // singleton, keyed under `lead_sourcing:cb`, so an autowired adapter would quietly share a
        // door with Companies House — one vendor's outage closing a provider it has nothing to do
        // with, and an open breaker landing on the wrong row of the operations screen.
        $this->app->singleton(ZeroBounceEmailVerifier::class, fn ($app): ZeroBounceEmailVerifier => new ZeroBounceEmailVerifier(
            $app->make(HttpFactory::class),
            $app->make('enrichment.breaker'),
        ));

        $this->app->singleton(EnrichmentProviderRegistry::class, function ($app): EnrichmentProviderRegistry {
            $registry = new EnrichmentProviderRegistry;

            $registry->register($app->make(ZeroBounceEmailVerifier::class));

            return $registry;
        });
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(DiscoveryRunCompleted::class, EnrichDiscoveredLeads::class);
    }
}
