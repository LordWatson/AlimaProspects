<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Events;

use App\Modules\Scoring\Actions\ScoreLeadAction;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a lead has been graded — or re-graded — against an ICP ({@see ScoreLeadAction}).
 * Dispatched `afterCommit` so a listener never reads a score that isn't there yet. The Leads
 * Workspace (Phase 10) listens for this to put the grade on the lead's timeline; Notifications
 * (Phase 12) will use it to tell a team about a strong new match.
 *
 * IDs and the graded values only: a listener re-reads the score inside the team's context. Carrying
 * the grade on the event keeps a listener that only wants to announce it from needing a query at
 * all.
 */
final class LeadScored
{
    use Dispatchable;

    public function __construct(
        public readonly int $teamId,
        public readonly int $leadId,
        public readonly int $scoreId,
        public readonly string $grade,
        public readonly int $score,
    ) {}

    public static function for(LeadScore $score): self
    {
        return new self(
            teamId: (int) $score->team_id,
            leadId: (int) $score->lead_id,
            scoreId: (int) $score->getKey(),
            grade: $score->grade->value,
            score: $score->score,
        );
    }
}
