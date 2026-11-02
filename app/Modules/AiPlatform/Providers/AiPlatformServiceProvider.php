<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Providers;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Contracts\CreditSource;
use App\Modules\AiPlatform\Observability\AiUsageCard;
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
use Livewire\Livewire;

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

        // The untrusted-block bound (Phase 20, ADR 0017), read here rather than inside the
        // renderer: `PromptInterpolator` is a pure string-in, string-out service whose unit tests
        // construct it with `new`, and a `config()` call inside it would make that impossible.
        // `bind`, not `singleton`, so a test that changes the limit gets the new one on resolve.
        $this->app->bind(PromptInterpolator::class, fn (): PromptInterpolator => new PromptInterpolator(
            (int) config(
                'ai-platform.limits.max_untrusted_chars',
                PromptInterpolator::DEFAULT_MAX_UNTRUSTED_CHARS,
            ),
        ));
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

    public function boot(): void
    {
        parent::boot();

        $this->registerPulseCard();
    }

    /**
     * The live **AI usage** card on the Pulse dashboard (Phase 20, ADR 0017).
     *
     * Registered here rather than in a global provider because the card is AI Platform's: it knows
     * what a prompt key and a cost micro are, which is exactly why Pulse cannot ship it. The
     * recorder that fills it is declared in `config/pulse.php` beside the framework's own.
     *
     * Guarded on Livewire's presence, not Pulse's. Pulse is a hard dependency, but it is only a
     * *dashboard* dependency: `PULSE_ENABLED=false` leaves the package installed and the Livewire
     * component registration harmless, whereas an installation that has pruned Livewire — or a
     * console command booting before it resolves — must not fail here over a card nobody is
     * looking at.
     */
    private function registerPulseCard(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('ai-usage', AiUsageCard::class);
    }
}
