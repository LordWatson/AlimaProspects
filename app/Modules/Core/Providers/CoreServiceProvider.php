<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Contracts\WebContentFetcher;
use App\Modules\Core\Services\HttpWebContentFetcher;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ModuleServiceProvider;

/**
 * Core module provider. Registers the tenancy {@see TeamContext} singleton, the shared SSRF-safe
 * {@see WebContentFetcher}, and (via the base provider) the module's routes and policies. Core is
 * the foundation every other module builds on.
 */
final class CoreServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TeamContext::class);

        // The shared web fetcher behind the contract — swapped for a fake in tests, and consumed by
        // Business Intelligence and Enrichment without either owning the SSRF-hardened fetch.
        $this->app->bind(WebContentFetcher::class, HttpWebContentFetcher::class);
    }
}
