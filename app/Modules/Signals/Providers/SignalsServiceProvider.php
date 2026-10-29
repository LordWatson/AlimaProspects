<?php

declare(strict_types=1);

namespace App\Modules\Signals\Providers;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\ModuleServiceProvider;
use App\Modules\Signals\Contracts\NewsSearchClient;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Policies\SignalPolicy;
use App\Modules\Signals\Policies\SignalWatchPolicy;
use App\Modules\Signals\Services\SerperNewsClient;
use App\Modules\Signals\Services\SignalItemNormaliser;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;

final class SignalsServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        SignalWatch::class => SignalWatchPolicy::class,
        Signal::class => SignalPolicy::class,
    ];

    public function register(): void
    {
        // The news client talks to Serper /news behind its own circuit breaker (separate from Lead
        // Sourcing's), configured from config/signals.php. Reuses the generic LeadSourcing breaker.
        $this->app->bind(NewsSearchClient::class, fn ($app): SerperNewsClient => new SerperNewsClient(
            $app->make(HttpFactory::class),
            new CircuitBreaker(
                $app->make(Cache::class),
                (int) config('signals.circuit_breaker.threshold', 5),
                (int) config('signals.circuit_breaker.cooldown', 60),
                prefix: 'signals:cb',
            ),
            $app->make(SignalItemNormaliser::class),
        ));
    }
}
