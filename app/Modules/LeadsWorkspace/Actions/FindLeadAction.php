<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;

/**
 * Resolve one lead for the workspace detail screen (Phase 10), scoped to the team, with everything
 * the page renders: its contacts, the run that found it, its scores (each with the ICP it was
 * measured against plus the viewer's own verdict), and the head of its activity timeline.
 *
 * Run inside the team context so the {@see BelongsToTeam} global scope hides other teams' leads —
 * a request for one that isn't the team's own 404s rather than binding cross-tenant. The policy
 * still double-checks ownership at the controller (ADR 0006).
 *
 * Everything the screen touches is eager-loaded, so `preventLazyLoading` stays quiet and the page
 * costs a fixed number of queries however much history a lead has.
 */
final class FindLeadAction
{
    /**
     * How much timeline the detail screen renders in one go. A lead that has been re-enriched and
     * re-scored for months accumulates entries, and the page is a summary, not an audit log — the
     * full history lives in `lead_activities` (and the audit log) for anyone who needs it.
     */
    public const int TIMELINE_LIMIT = 50;

    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @param  int  $forUserId  Only this member's score feedback is loaded — the screen shows the
     *                          viewer their own verdict, not the team's.
     */
    public function handle(Team $team, int $leadId, int $forUserId): Lead
    {
        return $this->teamContext->run($team, fn (): Lead => Lead::query()
            ->where('team_id', $team->getKey())
            ->with([
                // `team` on the lead and its scores: the policies read it, and a lazy read would
                // trip preventLazyLoading. One query covers every score (they share the team).
                'team',
                'contacts',
                'discoveryRun:id,source,status,created_at',
                'scores' => fn ($query) => $query->orderByDesc('scored_at'),
                'scores.team',
                'scores.icp:id,name,is_active',
                'scores.feedback' => fn ($query) => $query->where('user_id', $forUserId),
                'activities' => fn ($query) => $query
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id')
                    ->limit(self::TIMELINE_LIMIT),
                // The timeline attributes each entry to a person or to Alima, so the name is
                // needed; nothing else about the account is.
                'activities.user:id,name',
            ])
            ->findOrFail($leadId));
    }
}
