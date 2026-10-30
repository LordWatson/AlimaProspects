<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Events;

use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a discovery run finishes successfully ({@see DiscoverLeadsJob}), after the leads are
 * persisted and the run is settled Completed. Enrichment listens for this to fan out the per-lead
 * enrichment jobs (Phase 8). An internal, non-broadcast event — it carries only the team + run id,
 * never lead records. Firing an event keeps Lead Sourcing unaware of downstream enrichment.
 */
final class DiscoveryRunCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $runId,
    ) {}
}
