<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Providers;

use App\Modules\LeadSourcing\Contracts\LeadInterestGate;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Policies\DiscoveryRunPolicy;
use App\Modules\LeadSourcing\Services\AllowAllLeadInterestGate;
use App\Modules\LeadSourcing\Services\CompaniesHouseProvider;
use App\Modules\LeadSourcing\Services\DiscoveryLimitGuard;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use App\Modules\LeadSourcing\Services\StubLeadQuotaSource;
use App\Modules\LeadSourcing\Support\CircuitBreaker;
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
    ];

    public function register(): void
    {
        // Compliance + billing seams — unlimited/allow-all stubs until Phases 15 and 16 swap them.
        $this->app->bind(LeadQuotaSource::class, StubLeadQuotaSource::class);
        $this->app->bind(LeadInterestGate::class, AllowAllLeadInterestGate::class);

        // Shared circuit breaker for provider HTTP, configured from config/lead-sourcing.php.
        $this->app->singleton(CircuitBreaker::class, fn ($app): CircuitBreaker => new CircuitBreaker(
            $app->make(Cache::class),
            (int) config('lead-sourcing.circuit_breaker.threshold', 5),
            (int) config('lead-sourcing.circuit_breaker.cooldown', 60),
        ));

        // The provider registry: one entry per live source. New providers (Phase 7b) register here
        // and the discovery pipeline picks them up with no further change.
        $this->app->singleton(LeadSourceProviderRegistry::class, function ($app): LeadSourceProviderRegistry {
            $registry = new LeadSourceProviderRegistry;
            $registry->register(new CompaniesHouseProvider(
                $app->make(HttpFactory::class),
                $app->make(CircuitBreaker::class),
            ));

            return $registry;
        });

        $this->app->bind(DiscoveryLimitGuard::class, fn ($app): DiscoveryLimitGuard => new DiscoveryLimitGuard(
            $app->make(LeadQuotaSource::class),
        ));
    }
}
