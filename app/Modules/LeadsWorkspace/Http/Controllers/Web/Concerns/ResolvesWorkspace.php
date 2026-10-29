<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Controllers\Web\Concerns;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Http\Request;

/**
 * The resolution every workspace controller repeats: the caller, their team, and a team-scoped read
 * of the lead or stage being acted on.
 *
 * Reading inside {@see TeamContext} is what makes a cross-tenant request a **404 before any policy
 * runs** — the global scope hides the row, so the request can't even learn that it exists. The
 * policy still double-checks ownership afterwards (ADR 0006): a global scope is a convenience, not
 * the tenancy guarantee.
 */
trait ResolvesWorkspace
{
    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    protected function team(Request $request): Team
    {
        /** @var Team $team */
        $team = $this->user($request)->currentTeam()->firstOrFail();

        return $team;
    }

    /**
     * @param  list<string>  $with  Relations the caller's policy or response needs loaded. `team`
     *                              is always loaded, because every policy reads it and a lazy read
     *                              would trip preventLazyLoading.
     */
    protected function lead(Request $request, int $leadId, array $with = []): Lead
    {
        $team = $this->team($request);

        return app(TeamContext::class)->run($team, fn (): Lead => Lead::query()
            ->where('team_id', $team->getKey())
            ->with(['team', ...$with])
            ->findOrFail($leadId));
    }

    protected function stage(Request $request, int $stageId): PipelineStage
    {
        $team = $this->team($request);

        return app(TeamContext::class)->run($team, fn (): PipelineStage => PipelineStage::query()
            ->where('team_id', $team->getKey())
            ->with('team')
            ->findOrFail($stageId));
    }

    /**
     * A transient, team-scoped stage for authorising "may this member change the board at all",
     * which needs no stored row.
     */
    protected function transientStage(Team $team): PipelineStage
    {
        $stage = new PipelineStage;
        $stage->forceFill(['team_id' => $team->getKey()]);
        $stage->setRelation('team', $team);

        return $stage;
    }

    /**
     * A transient, team-scoped lead for authorising an ability that isn't about one lead — import,
     * export, a bulk action over a selection.
     */
    protected function transientLead(Team $team): Lead
    {
        $lead = new Lead;
        $lead->forceFill(['team_id' => $team->getKey()]);
        $lead->setRelation('team', $team);

        return $lead;
    }
}
