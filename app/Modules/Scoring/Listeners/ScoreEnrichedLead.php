<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Listeners;

use App\Modules\Enrichment\Events\LeadEnriched;
use App\Modules\Scoring\Jobs\ScoreLeadJob;

/**
 * Queues a score for a lead whose enriched data just changed (Phase 9). Enrichment is what gives
 * the deterministic scorer something to assess — industry, size, technologies, contact titles — so
 * a freshly enriched lead is scored immediately rather than waiting for the next ICP edit.
 *
 * Only dispatches a job (cheap); the scoring itself happens on the queue. The job is unique per
 * lead and the action is idempotent, so overlapping triggers can't double-score or double-spend.
 */
final class ScoreEnrichedLead
{
    public function handle(LeadEnriched $event): void
    {
        ScoreLeadJob::dispatch($event->teamId, $event->leadId);
    }
}
