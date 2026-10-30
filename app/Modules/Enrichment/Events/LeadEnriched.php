<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Events;

use App\Modules\Enrichment\Actions\EnrichLeadAction;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a {@see Lead} has been enriched and survived de-duplication, so its company facts and
 * contacts have changed. Scoring & Matching (Phase 9) listens for this to grade the lead against
 * the team's active ICP. Dispatched `afterCommit` by {@see EnrichLeadAction} so listeners never see
 * uncommitted state, and carries the **surviving** lead's id — a merged-away lead is never emitted.
 *
 * IDs only: a listener re-reads the lead inside the team's context.
 */
final class LeadEnriched
{
    use Dispatchable;

    public function __construct(
        public readonly int $teamId,
        public readonly int $leadId,
    ) {}

    public static function for(Lead $lead): self
    {
        return new self((int) $lead->team_id, (int) $lead->getKey());
    }
}
