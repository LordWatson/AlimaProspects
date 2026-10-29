<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;

/**
 * Shared set-up for the Leads Workspace tests. A class rather than the global test functions used
 * elsewhere in the suite: these fixtures are needed by half a dozen files, and PSR-4 autoloading
 * makes that explicit instead of depending on which test file Pest happened to load first.
 */
final class LeadsWorkspaceFixtures
{
    /**
     * A verified member with their own team and an active ICP for leads to be graded against.
     *
     * @return array{User, Team, Icp}
     */
    public static function team(): array
    {
        $user = createUserWithTeam();
        $team = $user->currentTeam()->firstOrFail();

        $icp = app(TeamContext::class)->run($team, fn (): Icp => Icp::factory()->active()->create([
            'team_id' => $team->getKey(),
        ]));

        return [$user, $team, $icp];
    }

    /**
     * A lead on the team, optionally graded against the given ICP.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function lead(
        Team $team,
        array $attributes = [],
        ?Icp $icp = null,
        ?ScoreGrade $grade = null,
        int $score = 85,
    ): Lead {
        return app(TeamContext::class)->run($team, function () use ($team, $attributes, $icp, $grade, $score): Lead {
            $lead = Lead::factory()->create([...$attributes, 'team_id' => $team->getKey()]);

            if ($icp instanceof Icp && $grade instanceof ScoreGrade) {
                LeadScore::factory()->grade($grade, $score)->create([
                    'team_id' => $team->getKey(),
                    'lead_id' => $lead->getKey(),
                    'icp_id' => $icp->getKey(),
                ]);
            }

            return $lead;
        });
    }

    /**
     * A second member of the given team, in the given role, with it as their active team.
     */
    public static function member(Team $team, TeamRole $role): User
    {
        $member = User::factory()->create();

        app(TeamMembership::class)->add($member, $team, $role);
        $member->forceFill(['current_team_id' => $team->getKey()])->save();

        return $member;
    }

    /**
     * A team of its own — nobody in the acting user's team belongs to it — with one lead, for the
     * cross-tenant tests.
     *
     * @return array{Team, Lead}
     */
    public static function foreignLead(): array
    {
        $team = Team::factory()->create();

        $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
            'team_id' => $team->getKey(),
        ]));

        return [$team, $lead];
    }
}
