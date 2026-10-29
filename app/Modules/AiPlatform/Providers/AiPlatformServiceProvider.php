<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Providers;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Contracts\CreditSource;
use App\Modules\AiPlatform\Services\AiCreditGuard;
use App\Modules\AiPlatform\Services\AiUsageLogger;
use App\Modules\AiPlatform\Services\ClaudeAiClient;
use App\Modules\AiPlatform\Services\CostCalculator;
use App\Modules\AiPlatform\Services\JsonSchemaValidator;
use App\Modules\AiPlatform\Services\PromptInterpolator;
use App\Modules\AiPlatform\Services\PromptRegistry;
use App\Modules\AiPlatform\Services\StubCreditSource;
use App\Modules\AiPlatform\Transport\LaravelAiTransport;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ModuleServiceProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Wires the AI Platform: the {@see AiClient} every feature calls, the SDK-backed {@see AiTransport},
 * and the stubbed {@see CreditSource} (replaced by Billing). Everything is bound to a contract so the
 * transport and credit source can be swapped without touching callers (ADR 0005 / mvp.md Phase 16).
 */
final class AiPlatformServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PromptRegistry::class);
        $this->app->singleton(AiTransport::class, LaravelAiTransport::class);
        $this->app->bind(CreditSource::class, StubCreditSource::class);

        $this->app->singleton(AiClient::class, function ($app): ClaudeAiClient {
            /** @var CacheRepository $cache */
            $cache = Cache::store(config('ai-platform.cache.store'));

            return new ClaudeAiClient(
                transport: $app->make(AiTransport::class),
                registry: $app->make(PromptRegistry::class),
                interpolator: $app->make(PromptInterpolator::class),
                validator: $app->make(JsonSchemaValidator::class),
                cost: $app->make(CostCalculator::class),
                usage: $app->make(AiUsageLogger::class),
                creditGuard: $app->make(AiCreditGuard::class),
                teamContext: $app->make(TeamContext::class),
                cache: $cache,
            );
        });
    }
}
