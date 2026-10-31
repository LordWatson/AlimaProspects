<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Admin\Enums\AdminRole;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
 * The seeded operator account (Phase 19a).
 *
 * Three properties, and the third is the one that matters outside a developer's machine: the seeder
 * plants a **known password**, so it must refuse to run in production. `php artisan db:seed --force`
 * on a production box is a thing people do while fixing something else.
 */

it('seeds a super admin who belongs to no team', function (): void {
    $this->seed(SuperAdminSeeder::class);

    $user = User::query()->where('email', SuperAdminSeeder::EMAIL)->firstOrFail();

    expect($user->adminRole())->toBe(AdminRole::SuperAdmin)
        ->and($user->isPlatformAdmin())->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('password', (string) $user->password))->toBeTrue()
        // An operator belongs to no tenant — which is the point of seeding one rather than
        // promoting a demo user. There is nothing for `LogContext` to put in `TeamContext`, so the
        // panel is exercised the way it is actually held.
        ->and($user->currentTeamId())->toBeNull()
        ->and($user->teams()->count())->toBe(0);
});

it('has no second factor, so the first visit is the enrolment screen', function (): void {
    $this->seed(SuperAdminSeeder::class);

    $user = User::query()->where('email', SuperAdminSeeder::EMAIL)->firstOrFail();

    expect($user->two_factor_confirmed_at)->toBeNull();

    // The correct local experience, and written down in the runbook rather than excepted in the
    // middleware.
    $this->actingAs($user)->get('/admin')->assertRedirect(route('security.edit'));
});

it('never resets a password somebody has changed', function (): void {
    $this->seed(SuperAdminSeeder::class);

    $user = User::query()->where('email', SuperAdminSeeder::EMAIL)->firstOrFail();
    $user->forceFill(['password' => Hash::make('a-real-password'), 'admin_role' => null])->save();

    $this->seed(SuperAdminSeeder::class);

    $user->refresh();

    // Idempotent in the way that matters: the role is refreshed, because that is what a re-seed is
    // for; the password is left exactly as it is, because putting `password` back on a machine
    // somebody secured would be worse than doing nothing.
    expect($user->adminRole())->toBe(AdminRole::SuperAdmin)
        ->and(Hash::check('a-real-password', (string) $user->password))->toBeTrue()
        ->and(User::query()->where('email', SuperAdminSeeder::EMAIL)->count())->toBe(1);
});

it('refuses to promote an account somebody registered', function (): void {
    // On any internet-facing non-production host with open registration, somebody could register
    // this address and wait to be handed `super_admin`. Registration runs
    // `CreatePersonalTeamAction`, so an account with a team is one a *person* signed up for — and
    // the account this seeder creates belongs to no team at all.
    $squatter = createUserWithTeam(['email' => SuperAdminSeeder::EMAIL]);

    expect(fn () => app(SuperAdminSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'already belongs to a registered account with a team');

    expect($squatter->refresh()->isPlatformAdmin())->toBeFalse();
});

it('keeps the rest of a production seed runnable', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    // `DatabaseSeeder` **skips** the super admin in production rather than attempting it and being
    // refused: calling it unconditionally would abort the whole run, so a `db:seed --force` on a
    // production box to register the roles or the prompt registry would take the rest of the list
    // down with it. That is the regression this asserts.
    //
    // Invoked directly, because `db:seed` stops to ask for confirmation in production before any
    // seeder is reached — and because `$this->seed()` returns a `PendingCommand` that runs on
    // destruct, where a failure would go unnoticed (see `tests/Feature/Core/DatabaseSeederTest`).
    app(DatabaseSeeder::class)->run();

    expect(User::query()->where('email', SuperAdminSeeder::EMAIL)->exists())->toBeFalse()
        ->and(Role::query()->count())->toBeGreaterThan(0);
});

it('refuses to run in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    // The seeder is invoked directly rather than through `db:seed`, which in production stops to
    // ask for confirmation before the seeder is ever reached — so going via the command would be
    // testing Artisan's prompt instead of this refusal.
    expect(fn () => app(SuperAdminSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'Refusing to seed');

    expect(User::query()->where('email', SuperAdminSeeder::EMAIL)->exists())->toBeFalse();
});

it('runs in production only when told to twice', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    // `--force` is Artisan's half; this is the other. One flag alone is not enough, because one
    // flag is what somebody sets while fixing something else.
    config()->set('admin.seed.allow_production', true);

    app(SuperAdminSeeder::class)->run();

    expect(User::query()->where('email', SuperAdminSeeder::EMAIL)->exists())->toBeTrue();
});
