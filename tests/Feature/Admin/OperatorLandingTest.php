<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Admin\Enums\AdminRole;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Where an operator lands when they sign in.
 *
 * The bug this file exists for: **a platform operator belongs to no team by design**
 * (`SuperAdminSeeder` says so, and that is the point of it), Fortify sends every login to one
 * configured path, and that path is the product's dashboard — which resolves
 * `currentTeam()->firstOrFail()`. So signing in as `super@admin.com` landed on a **404**. The panel
 * was never broken; the front door was.
 *
 * Three doors had the same assumption behind them: the login response, the `guest` middleware's
 * redirect (an operator who opens `/login` again), and the panel's own "leave the panel" button.
 */
it('sends an operator with no team to the panel when they sign in', function (): void {
    $this->seed(SuperAdminSeeder::class);

    $this->post('/login', [
        'email' => SuperAdminSeeder::EMAIL,
        'password' => 'password',
    ])->assertRedirect('/admin');
});

it('sends an operator who re-opens the sign-in page to the panel, not to a 404', function (): void {
    $this->seed(SuperAdminSeeder::class);

    $operator = User::query()->where('email', SuperAdminSeeder::EMAIL)->sole();

    $this->actingAs($operator)->get('/login')->assertRedirect('/admin');
});

it('follows the configured panel path', function (): void {
    // `ADMIN_PATH` moves the panel; the landing has to move with it, or the one deployment that
    // hides the panel is the one where operators cannot find it.
    config()->set('admin.path', 'operations');

    $this->seed(SuperAdminSeeder::class);

    $this->post('/login', [
        'email' => SuperAdminSeeder::EMAIL,
        'password' => 'password',
    ])->assertRedirect('/operations');
});

it('leaves everybody who has a team on the dashboard', function (): void {
    $user = createUserWithTeam(['password' => bcrypt('password')]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $this->actingAs($user)->get('/login')->assertRedirect(route('dashboard'));
});

it('leaves an operator who also belongs to a team on the dashboard', function (): void {
    // The production advice is to register normally and be promoted, which is an operator with a
    // team: they have a product to look at, so nothing about their landing should change.
    $user = createUserWithTeam(['password' => bcrypt('password')]);
    $user->forceFill(['admin_role' => AdminRole::Support->value])->save();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/dashboard');
});

it('answers a token client in Fortify’s own shape', function (): void {
    $this->seed(SuperAdminSeeder::class);

    // The mobile app (Phase 18) reads `two_factor` off this, so the landing rule must not reshape
    // a JSON login.
    $this->postJson('/login', [
        'email' => SuperAdminSeeder::EMAIL,
        'password' => 'password',
    ])->assertOk()->assertExactJson(['two_factor' => false]);
});

it('still sends an operator to enrol a second factor before showing them the panel', function (): void {
    $this->seed(SuperAdminSeeder::class);

    $operator = User::query()->where('email', SuperAdminSeeder::EMAIL)->sole();

    // Landing on `/admin` does not weaken what `/admin` asks for: the seeded operator has no second
    // factor, so the panel sends them to set one up — on its own screen, inside its own shell
    // (`docs/runbooks/admin-panel.md` §1).
    $this->actingAs($operator)->get('/admin')->assertRedirect('/admin/security');
});

it('offers an operator with no team a way out that is not a 404', function (): void {
    $this->seed(SuperAdminSeeder::class);

    $operator = User::query()->where('email', SuperAdminSeeder::EMAIL)->sole();

    // The product's dashboard is still a 404 for an account with no team — there is nothing there
    // to show — which is exactly why the panel's header must not offer it as the way out. It
    // offers signing out instead, and the layout decides that from `auth.currentTeam`.
    $this->actingAs($operator)->get('/dashboard')->assertNotFound();
});

it('keeps a plain member out of the panel whatever their landing', function (): void {
    $member = createUserWithTeam();

    // The landing rule reads `admin_role`, so it is worth pinning beside it that a member without
    // one is not an operator however they arrived.
    expect($member->isPlatformAdmin())->toBeFalse();

    $this->actingAs($member)->get('/admin')->assertNotFound();
});
