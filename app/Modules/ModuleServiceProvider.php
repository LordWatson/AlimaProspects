<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base provider for every domain module (ADR 0002). Auto-registers the module's web routes
 * (`routes/web.php`, `web` middleware) and API routes (`routes/api.php`, `api` middleware under
 * the versioned `/api/v1` prefix), plus any declared policies. Concrete module providers extend
 * this and are registered in bootstrap/providers.php.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Policy map: model class => policy class.
     *
     * @var array<class-string, class-string>
     */
    protected array $policies = [];

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerPolicies();
    }

    protected function registerRoutes(): void
    {
        $web = $this->modulePath().'/routes/web.php';

        if (is_file($web)) {
            Route::middleware('web')->group($web);
        }

        $api = $this->modulePath().'/routes/api.php';

        if (is_file($api)) {
            Route::middleware(['api', 'throttle:api'])
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group($api);
        }
    }

    protected function registerPolicies(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /**
     * Absolute path to the module root (the directory containing Providers/, routes/, etc.).
     */
    protected function modulePath(): string
    {
        $providerFile = (new ReflectionClass($this))->getFileName();

        // Providers live in <module>/Providers/<Name>ServiceProvider.php → module root is two levels up.
        return dirname((string) $providerFile, 2);
    }
}
