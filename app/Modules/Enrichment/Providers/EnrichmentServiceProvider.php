<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Providers;

use App\Modules\Enrichment\Contracts\DnsResolver;
use App\Modules\Enrichment\Listeners\EnrichDiscoveredLeads;
use App\Modules\Enrichment\Services\SystemDnsResolver;
use App\Modules\LeadSourcing\Events\DiscoveryRunCompleted;
use App\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Enrichment & Data Quality module provider (Phase 8). Binds the {@see DnsResolver} seam used by the
 * free-tier email check (swapped for a fake in tests), and wires the listener that fans out
 * enrichment when a discovery run completes. The shared web fetcher it uses is bound in Core.
 */
final class EnrichmentServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(DiscoveryRunCompleted::class, EnrichDiscoveredLeads::class);
    }
}
