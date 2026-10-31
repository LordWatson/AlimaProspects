<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Contracts\WebContentFetcher;
use App\Modules\Core\Services\AllCapabilitiesEnabled;
use App\Modules\Core\Services\HttpWebContentFetcher;
use App\Modules\Core\Support\ApiDocs\DocumentsApiContract;
use App\Modules\Core\Support\AuditContext;
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

        // Ambient audit properties for the request or job (Phase 19a).
        //
        // `scoped`, **not** `singleton`, and the distinction is the whole safety property: Laravel
        // flushes scoped instances between requests and between queue jobs, so an
        // `impersonated_by` pushed in by one request can never stamp the next request's entries in
        // the same process. A singleton would hold it for the life of an Octane worker or a test
        // process — which is exactly how a false attribution gets into an audit trail.
        $this->app->scoped(AuditContext::class);

        // The shared web fetcher behind the contract — swapped for a fake in tests, and consumed by
        // Business Intelligence and Enrichment without either owning the SSRF-hardened fetch.
        $this->app->bind(WebContentFetcher::class, HttpWebContentFetcher::class);

        // The capability seam (Phase 19b, ADR 0015). Permissive here on purpose: an
        // installation with neither Billing nor Admin bound behaves exactly as the product did
        // before capabilities existed, rather than switching six features off for everybody.
        // Billing decorates it with the plan's list; Admin decorates that with the operator's
        // levers, and provider order gives that composition for free.
        // `scoped`, not `bind`. The decorators above this one memoise per team for the life of a
        // request or job — deliberately, because the guards ask on every discovery run and the
        // answer must not change mid-request. With `bind`, `extend` rebuilt the whole chain on
        // every resolution, so the memo was per-resolution and `forget()` cleared a throwaway.
        // `scoped` is also what a `queue:work` process needs: the container flushes scoped
        // instances between jobs, so one worker does not carry a suspended team's answer into the
        // next job.
        $this->app->scoped(CapabilityGate::class, AllCapabilitiesEnabled::class);
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
