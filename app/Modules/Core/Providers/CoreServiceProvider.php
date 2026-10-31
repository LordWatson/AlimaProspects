<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Contracts\WebContentFetcher;
use App\Modules\Core\Services\HttpWebContentFetcher;
use App\Modules\Core\Support\ApiDocs\DocumentsApiContract;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ModuleServiceProvider;
use Dedoc\Scramble\Scramble;

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

    public function boot(): void
    {
        parent::boot();

        $this->registerApiDocumentation();
    }

    /**
     * Teach the OpenAPI generator the parts of the `/api/v1` contract that no controller signature
     * can express - the shared error envelope and a stable server URL (Phase 17).
     *
     * Guarded, not assumed: Scramble is a build-time dev dependency, so on a production boot the
     * class is absent and there is nothing to document.
     */
    private function registerApiDocumentation(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        Scramble::configure()->withDocumentTransformers(new DocumentsApiContract);
    }
}
