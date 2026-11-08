<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Providers;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\LeadSourcing\Console\BackfillEvidenceCommand;
use App\Modules\LeadSourcing\Console\EvidenceSourceQualityCommand;
use App\Modules\LeadSourcing\Contracts\CompanyWebsiteResolver;
use App\Modules\LeadSourcing\Contracts\LeadInterestGate;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use App\Modules\LeadSourcing\Contracts\SubscriberTypeResolver;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\LeadSourcing\Observers\LeadProvenanceObserver;
use App\Modules\LeadSourcing\Policies\DiscoveryRunPolicy;
use App\Modules\LeadSourcing\Policies\LeadEvidencePolicy;
use App\Modules\LeadSourcing\Policies\LeadPolicy;
use App\Modules\LeadSourcing\Services\AiLeadSourceProvider;
use App\Modules\LeadSourcing\Services\AllowAllLeadInterestGate;
use App\Modules\LeadSourcing\Services\CompaniesHouseProvider;
use App\Modules\LeadSourcing\Services\DiscoveryLimitGuard;
use App\Modules\LeadSourcing\Services\GooglePlacesProvider;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use App\Modules\LeadSourcing\Services\NoSubscriberType;
use App\Modules\LeadSourcing\Services\SerperCompanyWebsiteResolver;
use App\Modules\LeadSourcing\Services\StubLeadQuotaSource;
use App\Modules\LeadSourcing\Services\WebSearchProvider;
use App\Modules\ModuleServiceProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;

final class LeadSourcingServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        DiscoveryRun::class => DiscoveryRunPolicy::class,
        Lead::class => LeadPolicy::class,
        LeadEvidence::class => LeadEvidencePolicy::class,
    ];

    public function register(): void
    {
        // Compliance + billing seams. These are the *defaults*, so this module works on its own;
        // Compliance (Phase 15) and Billing (Phase 16) bind the real gate and the real quota source
        // over them, which is why neither name appears anywhere in this module.
        $this->app->bind(LeadQuotaSource::class, StubLeadQuotaSource::class);
        $this->app->bind(LeadInterestGate::class, AllowAllLeadInterestGate::class);

        // PL5's seam: what kind of subscriber a prospect is for direct-marketing purposes. Null by
        // default, because this module must not know what PECR is — Compliance binds the answer.
        $this->app->bind(SubscriberTypeResolver::class, NoSubscriberType::class);

        // Enrichment (Phase 8) resolves a homepage for a website-less lead through this seam — the
        // default goes to Serper (ADR 0010); tests bind a fake. It lives here, not in Enrichment,
        // because web search is this module's provider and its credentials and breaker are here.
        $this->app->bind(CompanyWebsiteResolver::class, fn ($app): SerperCompanyWebsiteResolver => new SerperCompanyWebsiteResolver(
            $app->make(HttpFactory::class),
            $app->make(CircuitBreaker::class),
        ));

        // Shared circuit breaker for provider HTTP, configured from config/lead-sourcing.php.
        $this->app->singleton(CircuitBreaker::class, fn ($app): CircuitBreaker => new CircuitBreaker(
            $app->make(Cache::class),
            (int) config('lead-sourcing.circuit_breaker.threshold', 5),
            (int) config('lead-sourcing.circuit_breaker.cooldown', 60),
            prefix: 'lead_sourcing:cb',
        ));

        // The provider registry: one entry per live source. New providers (Phase 7b) register here
        // and the discovery pipeline picks them up with no further change.
        $this->app->singleton(LeadSourceProviderRegistry::class, function ($app): LeadSourceProviderRegistry {
            $http = $app->make(HttpFactory::class);
            $breaker = $app->make(CircuitBreaker::class);

            $registry = new LeadSourceProviderRegistry;
            $registry->register(new CompaniesHouseProvider($http, $breaker));
            $registry->register(new GooglePlacesProvider($http, $breaker));
            $registry->register(new WebSearchProvider($http, $breaker));
            // No HTTP client and no breaker: this one goes through the AI Platform, which owns the
            // transport, the retries, the credit allowance and the cost ledger.
            $registry->register(new AiLeadSourceProvider($app->make(AiClient::class)));

            return $registry;
        });

        $this->app->bind(DiscoveryLimitGuard::class, fn ($app): DiscoveryLimitGuard => new DiscoveryLimitGuard(
            $app->make(LeadQuotaSource::class),
        ));
    }

    public function boot(): void
    {
        parent::boot();

        // Every lead records where it came from as its first item of evidence (PL1). Hung off the
        // row rather than called from each creating Action, so the "every lead has evidence"
        // guarantee survives the next path that creates one — see the observer's own note.
        Lead::observe(LeadProvenanceObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillEvidenceCommand::class,
                EvidenceSourceQualityCommand::class,
            ]);
        }
    }
}
