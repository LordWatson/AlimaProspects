<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Auth\Actions\CreateTeamAction;
use App\Modules\Auth\Data\CreateTeamData;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A full, explorable demo tenancy so the app can be driven end-to-end locally.
 *
 * Builds two teams — one UK / English, one UAE / Arabic (so RTL + Tajawal + ar date/number
 * formatting are visible) — a cast of members spanning all four {@see TeamRole} values, and a
 * couple of pending/expired invitations. The headline account is **Alex Watson** so you can log
 * in immediately:
 *
 *   alex@user.com  /  password   (Owner of Alima UK, Admin of Alima Gulf)
 *
 * Everything goes through the real Actions / {@see TeamMembership} so the demo data is identical
 * in shape to what registration and invitations produce (pivot role + spatie role kept in sync,
 * tenancy scoping honoured). Idempotent: re-running `db:seed` is a no-op once Alex exists.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->where('email', 'alex@user.com')->exists()) {
            $this->command?->info('DemoSeeder: demo data already present — skipping.');

            return;
        }

        /** @var CreateTeamAction $createTeam */
        $createTeam = app(CreateTeamAction::class);
        /** @var TeamMembership $membership */
        $membership = app(TeamMembership::class);
        /** @var TeamContext $teamContext */
        $teamContext = app(TeamContext::class);

        // --- People (every account: password = "password", email verified) --------------------
        $alex = $this->user('Alex Watson', 'alex@user.com');
        $layla = $this->user('Layla Hassan', 'layla@user.com');
        $sam = $this->user('Sam Carter', 'sam@user.com');
        $priya = $this->user('Priya Shah', 'priya@user.com');
        $omar = $this->user('Omar Farouk', 'omar@user.com');

        // --- Teams (created by their owner, as registration would) -----------------------------
        // Alex owns the UK / English team and lands here on login.
        $uk = $createTeam->handle($alex, new CreateTeamData(
            name: 'Alima UK',
            timezone: 'Europe/London',
            locale: 'en',
            market: 'uk',
        ), switchTo: true);

        // Layla owns the UAE / Arabic team — switch to it to experience RTL + Tajawal.
        $gulf = $createTeam->handle($layla, new CreateTeamData(
            name: 'Alima Gulf',
            timezone: 'Asia/Dubai',
            locale: 'ar',
            market: 'uae',
        ), switchTo: true);

        // --- Memberships spanning all four roles ----------------------------------------------
        DB::transaction(function () use ($membership, $uk, $gulf, $alex, $layla, $sam, $priya, $omar): void {
            // Alima UK: Alex=Owner (already), + Admin / Member / Read-only teammates.
            $membership->add($sam, $uk, TeamRole::Admin);
            $membership->add($priya, $uk, TeamRole::Member);
            $membership->add($omar, $uk, TeamRole::ReadOnly);
            $membership->add($layla, $uk, TeamRole::Member);

            // Alima Gulf: Layla=Owner (already), Alex=Admin (so he can switch + see RTL), Omar=Member.
            $membership->add($alex, $gulf, TeamRole::Admin);
            $membership->add($omar, $gulf, TeamRole::Member);
        });

        // --- Pending / expired invitations so the invitations UI isn't empty -------------------
        $this->invitation($teamContext, $uk, $alex, 'newteammate@user.com', TeamRole::Member);
        $this->invitation($teamContext, $uk, $alex, 'readonly.invite@user.com', TeamRole::ReadOnly, expired: true);
        $this->invitation($teamContext, $gulf, $layla, 'gulf.invite@user.com', TeamRole::Admin);

        $this->command?->info('DemoSeeder: seeded 5 users, 2 teams (UK/en + UAE/ar) and 3 invitations.');
        $this->command?->info('DemoSeeder: log in with  alex@user.com  /  password');
    }

    /**
     * Create a verified user whose password is "password".
     */
    private function user(string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
        ]);
    }

    /**
     * Create a pending (or expired) invitation scoped to the given team, as the inviter.
     */
    private function invitation(
        TeamContext $teamContext,
        Team $team,
        User $inviter,
        string $email,
        TeamRole $role,
        bool $expired = false,
    ): TeamInvitation {
        return $teamContext->run($team, function () use ($team, $inviter, $email, $role, $expired): TeamInvitation {
            $factory = TeamInvitation::factory()->state([
                'team_id' => $team->getKey(),
                'invited_by' => $inviter->getKey(),
                'email' => $email,
                'role' => $role->value,
            ]);

            return ($expired ? $factory->expired() : $factory)->create();
        });
    }
}
