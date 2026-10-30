<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Http\Controllers\Concerns;

use App\Models\User;
use App\Modules\AiInsights\Models\Insight;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Http\Request;

/**
 * The resolution both insight controllers repeat — web and API alike: the caller, their team, and
 * a team-scoped read of the insight being acted on.
 *
 * Reading inside {@see TeamContext} is what makes a cross-tenant request a **404 before any policy
 * runs**: the global scope hides the row, so the request cannot even learn that it exists. The
 * policy still double-checks ownership afterwards (ADR 0006) — a global scope is a convenience,
 * not the tenancy guarantee.
 *
 * `team` is always eager-loaded, because the policy reads it and a lazy read would trip
 * `preventLazyLoading`.
 */
trait ResolvesInsights
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

    protected function insight(Request $request, int $insightId): Insight
    {
        $team = $this->team($request);

        return app(TeamContext::class)->run($team, fn (): Insight => Insight::query()
            ->where('team_id', $team->getKey())
            ->with('team')
            ->findOrFail($insightId));
    }

    /**
     * A transient, team-scoped insight for authorising "may this member see the feed at all", which
     * needs no stored row.
     */
    protected function transientInsight(Team $team): Insight
    {
        $insight = new Insight;
        $insight->forceFill(['team_id' => $team->getKey()]);
        $insight->setRelation('team', $team);

        return $insight;
    }
}
