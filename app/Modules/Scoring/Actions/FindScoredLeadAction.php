<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Actions;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;

/**
 * Resolve a single lead by id, scoped to the team, with everything the score screen renders: its
 * contacts, its scores (each with the ICP it was measured against) and the current member's
 * feedback on them. Run inside the team context so the {@see BelongsToTeam} global scope hides
 * other teams' leads — a request for one that isn't the team's own 404s rather than binding
 * cross-tenant. Policies still double-check ownership at the controller.
 *
 * Everything the screen touches is eager-loaded, so `preventLazyLoading` stays quiet and the page
 * is a fixed number of queries however many scores a lead has.
 */
final class FindScoredLeadAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @param  int  $forUserId  Only this member's feedback is loaded — the screen shows the viewer
     *                          their own verdict, not the team's.
     */
    public function handle(Team $team, int $leadId, int $forUserId): Lead
    {
        return $this->teamContext->run($team, fn (): Lead => Lead::query()
            ->where('team_id', $team->getKey())
            ->with([
                // `team` on both the lead and its scores: the policies read it, and a lazy read
                // would trip preventLazyLoading. One query covers every score (same team).
                'team',
                'contacts',
                'scores' => fn ($query) => $query->orderByDesc('scored_at'),
                'scores.team',
                'scores.icp:id,name,is_active',
                'scores.feedback' => fn ($query) => $query->where('user_id', $forUserId),
            ])
            ->findOrFail($leadId));
    }
}
