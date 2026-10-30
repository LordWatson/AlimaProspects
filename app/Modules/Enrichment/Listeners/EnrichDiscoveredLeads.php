<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Listeners;

use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Jobs\EnrichLeadJob;
use App\Modules\LeadSourcing\Events\DiscoveryRunCompleted;
use App\Modules\LeadSourcing\Models\Lead;

/**
 * Fans out enrichment when a discovery run completes (Phase 8): queues one {@see EnrichLeadJob} per
 * still-pending lead in the run. Runs in the team's context so the tenant-scoped query is correct,
 * and only dispatches jobs (cheap) — the enrichment work itself happens on the queue. Leads already
 * enriched/enriching are skipped, so a re-run never doubles up.
 */
final class EnrichDiscoveredLeads
{
    public function __construct(private readonly TeamContext $teamContext) {}

    public function handle(DiscoveryRunCompleted $event): void
    {
        $this->teamContext->run($event->teamId, function () use ($event): void {
            Lead::query()
                ->where('discovery_run_id', $event->runId)
                ->where('enrichment_status', EnrichmentStatus::Pending)
                ->pluck('id')
                ->each(fn (int $leadId) => EnrichLeadJob::dispatch($event->teamId, $leadId));
        });
    }
}
