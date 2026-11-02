<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Services\TeamSuspension;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;

/**
 * Shared set-up for the Admin panel tests (Phase 19a).
 *
 * A class rather than global test functions, like {@see ComplianceFixtures}: the suspension
 * fixtures are needed from the Billing and Outreach suites too, and PSR-4 makes that explicit
 * instead of depending on which file Pest happened to load first.
 *
 * {@see self::operator()} gives its operators a **confirmed second factor**, because the real
 * middleware requires one and a suite that switched the requirement off in config would be testing
 * a deployment nobody runs. The one test that cares about an operator *without* 2FA creates that
 * case explicitly, which is the right way round.
 */
final class AdminFixtures
{
    /**
     * Distinguishes operators created in the same test. The address is still readable in a failure
     * message, which a factory's random one would not be — but a fixed one collides the moment a
     * test needs two operators of the same role, which several do.
     */
    private static int $sequence = 0;

    /**
     * A platform operator: in no team, no `current_team_id`, second factor confirmed. Exactly what
     * `SuperAdminSeeder` produces, plus the 2FA the middleware insists on.
     */
    public static function operator(AdminRole $role = AdminRole::SuperAdmin): User
    {
        $slug = strtolower(str_replace('_', '-', $role->value));

        $user = User::factory()->create([
            'name' => $role->label(),
            'email' => $slug.'-'.(++self::$sequence).'@alima.test',
        ]);

        $user->forceFill([
            'admin_role' => $role->value,
            'current_team_id' => null,
            'two_factor_secret' => 'encrypted-secret',
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
    }

    /**
     * An operator who has not set up two-factor authentication yet — the state the freshly seeded
     * `super@admin.com` is in on its first visit.
     */
    public static function operatorWithoutTwoFactor(AdminRole $role = AdminRole::SuperAdmin): User
    {
        $user = self::operator($role);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $user->refresh();
    }

    /**
     * A verified Owner with their own team — an ordinary customer.
     *
     * `$team` sets columns the factory does not take, and `market` and `locale` are the two that
     * matter: announcement targeting reads the first and announcement copy the second, so a test
     * about either needs a tenant that is actually in that market or reading that language.
     *
     * @param  array<string, mixed>  $team
     * @return array{User, Team}
     */
    public static function tenant(?string $name = null, array $team = []): array
    {
        $user = createUserWithTeam();
        $model = $user->currentTeam()->firstOrFail();

        if ($name !== null) {
            $team['name'] = $name;
        }

        if ($team !== []) {
            $model->forceFill($team)->save();
        }

        return [$user, $model->refresh()];
    }

    /**
     * A colleague on `$team` — somebody who reads the same announcements as the Owner but dismisses
     * them separately.
     */
    public static function member(Team $team, TeamRole $role = TeamRole::Member): User
    {
        $user = User::factory()->create();

        app(TeamMembership::class)->add($user, $team, $role);

        return $user->refresh();
    }

    /**
     * Suspend a team the way `SuspendTeamAction` does, without going through the panel.
     *
     * The suspension guards memoise per request and per job, so the memo is dropped here too —
     * a test that suspended a team and then asked a guard in the same request would otherwise get
     * the answer from before.
     */
    public static function suspend(Team $team, string $reason = 'Repeated spam complaints.'): Team
    {
        $team->forceFill([
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ])->save();

        app(TeamSuspension::class)->forget();

        return $team->refresh();
    }

    /**
     * A lead owned by `$team`, created inside that team's context so `BelongsToTeam` stamps it.
     */
    public static function lead(Team $team, string $company): Lead
    {
        return app(TeamContext::class)->run(
            $team,
            fn (): Lead => Lead::factory()->create([
                'team_id' => $team->getKey(),
                'company_name' => $company,
            ]),
        );
    }
}
