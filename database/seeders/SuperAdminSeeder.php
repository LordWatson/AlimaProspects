<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Admin\Enums\AdminRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The super-admin account for local work and CI (Phase 19a).
 *
 * `super@admin.com` / `password`, a super admin, email verified, and — deliberately — **in no team
 * and with no `current_team_id`**. That last part is the whole point of seeding it rather than
 * promoting a demo user: an operator belongs to no tenant, so the panel is exercised the way it is
 * actually held, with nothing for `LogContext` to put in `TeamContext` and nothing for a forgotten
 * team scope to quietly narrow.
 *
 * **It refuses to run in production** unless both `ADMIN_SEED_ALLOW_PRODUCTION=true` and `--force`
 * are given. A seeder that plants a known password on a public host is not a convenience, it is the
 * breach — and `php artisan db:seed --force` on a production box is a thing people do while fixing
 * something else.
 *
 * Idempotent, and carefully so: it matches on the email, refreshes the role and the verification,
 * and **never resets a password somebody has changed**. Re-running it on a machine where the
 * operator set a real password must not quietly put `password` back.
 *
 * The seeded account has no second factor, so its first `/admin` visit is the enrolment screen —
 * `EnsureAdminTwoFactor` sends it there. That is the correct local experience and it belongs in
 * `docs/runbooks/admin-panel.md`, not as an exception in the middleware.
 */
class SuperAdminSeeder extends Seeder
{
    public const string EMAIL = 'super@admin.com';

    public function run(): void
    {
        $this->assertNotProduction();

        $existing = User::query()->where('email', self::EMAIL)->first();

        if ($existing instanceof User) {
            // **Refuse to promote an account that belongs to a team.**
            //
            // A re-seed should refresh the account this seeder created, and that account is in no
            // team. Registration, by contrast, runs `CreatePersonalTeamAction` — so an account with
            // a team is one a *person* signed up for. On any internet-facing non-production host
            // with open registration, somebody could register this address and wait to be handed
            // `super_admin`, which is a privilege escalation with a seeder for a delivery mechanism.
            if ($existing->teams()->exists()) {
                throw new RuntimeException(
                    self::EMAIL.' already belongs to a registered account with a team. Refusing to '
                    .'grant it super_admin: this seeder only ever refreshes the account it created, '
                    .'which belongs to no team.'
                );
            }

            // Role and verification are refreshed — those are the things a re-seed is for. The
            // password is left exactly as it is.
            $existing->forceFill([
                'admin_role' => AdminRole::SuperAdmin->value,
                'email_verified_at' => $existing->email_verified_at ?? now(),
            ])->save();

            return;
        }

        $user = new User;

        $user->forceFill([
            'name' => 'Super Admin',
            'email' => self::EMAIL,
            // Hashed explicitly rather than left to the model's `hashed` cast, because `forceFill`
            // on a fresh instance is the one path where relying on the cast is easy to get wrong.
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'admin_role' => AdminRole::SuperAdmin->value,
            'current_team_id' => null,
        ])->save();
    }

    /**
     * @throws RuntimeException
     */
    private function assertNotProduction(): void
    {
        if (! app()->isProduction()) {
            return;
        }

        if ((bool) config('admin.seed.allow_production', false)) {
            return;
        }

        throw new RuntimeException(
            'Refusing to seed '.self::EMAIL.' in production: it has a known password. '
            .'Set ADMIN_SEED_ALLOW_PRODUCTION=true if you genuinely mean it, and change the '
            .'password immediately afterwards.'
        );
    }
}
