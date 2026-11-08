<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Providers;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Enrichment\Contracts\ContactApprovalGate;
use App\Modules\Enrichment\Contracts\CreditReserver;
use App\Modules\Enrichment\Contracts\DnsResolver;
use App\Modules\Enrichment\Listeners\EnrichDiscoveredLeads;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Enrichment\Policies\LeadContactPolicy;
use App\Modules\Enrichment\Services\AllowAllContactApproval;
use App\Modules\Enrichment\Services\AttemptRecorder;
use App\Modules\Enrichment\Services\EnrichmentProviderRegistry;
use App\Modules\Enrichment\Services\EnrichmentWaterfall;
use App\Modules\Enrichment\Services\PhoneNormaliser;
use App\Modules\Enrichment\Services\ProviderRateLimiter;
use App\Modules\Enrichment\Services\SystemDnsResolver;
use App\Modules\Enrichment\Services\UnmeteredCreditReserver;
use App\Modules\Enrichment\Services\ZeroBounceEmailVerifier;
use App\Modules\ICP\Actions\FindActiveIcpAction;
use App\Modules\LeadSourcing\Events\DiscoveryRunCompleted;
use App\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use libphonenumber\PhoneNumberUtil;

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

        // The two PL3 seams, with the defaults that let this module work on its own. Billing binds
        // the real reserver in PL4 (the fifth seam of ADR 0012's kind) and Compliance binds the
        // real approval gate in PL5 — which is why neither module's name appears here.
        $this->app->bind(CreditReserver::class, UnmeteredCreditReserver::class);
        $this->app->bind(ContactApprovalGate::class, AllowAllContactApproval::class);

        // libphonenumber's util is a heavy singleton holding the metadata tables; resolving it per
        // call would parse them per call.
        $this->app->singleton(PhoneNumberUtil::class, static fn (): PhoneNumberUtil => PhoneNumberUtil::getInstance());

        // The waterfall takes the **enrichment** breaker, for the same reason the adapters do:
        // `CircuitBreaker::class` is Lead Sourcing's, and a vendor outage is not a registry outage.
        $this->app->bind(EnrichmentWaterfall::class, fn ($app): EnrichmentWaterfall => new EnrichmentWaterfall(
            $app->make(EnrichmentProviderRegistry::class),
            $app->make(ProviderRateLimiter::class),
            $app->make('enrichment.breaker'),
            $app->make(AttemptRecorder::class),
            $app->make(PhoneNormaliser::class),
            $app->make(ContactApprovalGate::class),
            $app->make(FindActiveIcpAction::class),
        ));

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

    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        LeadContact::class => LeadContactPolicy::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Event::listen(DiscoveryRunCompleted::class, EnrichDiscoveredLeads::class);

        // A reveal and an enrichment request both spend the team's allowance, so cap how fast one
        // member can ask. The allowance bounds a month; this bounds a loop — a script, a stuck
        // button, an impatient click — and the two are different problems.
        RateLimiter::for('enrichment', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('enrichment.rate_limit_per_member', 30)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
