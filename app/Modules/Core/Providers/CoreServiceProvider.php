<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Support\TeamContext;
use App\Modules\ModuleServiceProvider;

/**
 * Core module provider. Registers the tenancy {@see TeamContext} singleton and (via the base
 * provider) the module's routes and policies. Core is the foundation every other module builds on.
 */
final class CoreServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TeamContext::class);
    }
}
