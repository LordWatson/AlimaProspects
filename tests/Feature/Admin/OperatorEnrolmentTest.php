<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Http\Middleware\EnsureAdminTwoFactor;
use App\Modules\Admin\Http\Middleware\PlatformContext;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The operator's way in, end to end.
 *
 * The panel has required a second factor since 19a. What was wrong was where it sent somebody to
 * get one: the **product's** security settings, inside the customer shell — and an operator belongs
 * to no team, so every destination in that shell's rail answers `404`. A working panel read as a
 * broken product: "it redirects me to a password screen and all the nav links 404".
 *
 * So the panel owns enrolment. These tests walk the whole path a fresh operator takes.
 */
function seededOperator(): User
{
    test()->seed(SuperAdminSeeder::class);

    return User::query()->where('email', SuperAdminSeeder::EMAIL)->sole();
}

it('walks a fresh operator from sign-in to a working panel', function (): void {
    $operator = seededOperator();

    // 1. Signing in opens the panel rather than the product's dashboard, which an account with no
    //    team cannot render.
    $this->post('/login', [
        'email' => SuperAdminSeeder::EMAIL,
        'password' => 'password',
    ])->assertRedirect('/admin');

    // 2. The panel sends them to enrolment — **its own**, in its own shell.
    $this->get('/admin')->assertRedirect(route('admin.security.edit'));

    // 3. Which renders, with the panel's layout around it and the reason on it.
    $this->get(route('admin.security.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Security')
            ->where('required', true)
            ->where('twoFactorEnabled', false)
            ->where('canManageTwoFactor', true)
            // `ADMIN_PATH` can move the panel, so the way on is a prop and not a hard-coded link.
            ->where('panelUrl', route('admin.dashboard')));

    // 4. Enrolment itself is Fortify's, unchanged — the password confirmation included.
    $this->post('/user/confirm-password', ['password' => 'password'])->assertRedirect();
    $this->post('/user/two-factor-authentication')->assertRedirect();

    fakeTwoFactorCode('123456');

    $this->post('/user/confirmed-two-factor-authentication', ['code' => '123456'])
        ->assertRedirect();

    expect($operator->refresh()->two_factor_confirmed_at)->not->toBeNull();

    // 5. And now the panel opens.
    $this->get('/admin')->assertOk();
    $this->get(route('admin.security.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('twoFactorEnabled', true));
});

it('opens every screen in the panel’s rail once the operator has enrolled', function (): void {
    // The complaint was "all the links on the side navbar 404". They did — in the *product's* rail,
    // which is where the old redirect left the operator. This asserts the panel's own.
    $operator = seededOperator();

    $operator->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt((string) json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $broken = [];

    foreach (Route::getRoutes() as $route) {
        if (! Str::startsWith((string) $route->getName(), 'admin.')
            || ! in_array('GET', $route->methods(), true)
            || Str::contains($route->uri(), '{')) {
            continue;
        }

        $status = $this->actingAs($operator->refresh())->get('/'.ltrim($route->uri(), '/'))->status();

        // 302 is allowed: `reporting/export` redirects back when asked for without a window.
        if (! in_array($status, [200, 302], true)) {
            $broken[] = $route->uri().' → '.$status;
        }
    }

    expect($broken)->toBe([]);
});

it('tells the panel where it lives, so moving it does not break every link', function (): void {
    // `ADMIN_PATH` moves the panel — the runbook recommends it for production — and the routes move
    // with it, because they are registered under `prefix(config('admin.path'))`. The rail's links
    // were hard-coded `/admin/...`, so the one deployment that hid the panel was the one where
    // every link inside it answered 404. The server shares the real base now, and the rail builds
    // every link from it.
    //
    // Asserted at the default path: the routes are registered at boot, so a test cannot move them
    // by setting config afterwards — and a prop that disagreed with the live route table would be
    // a worse test than none. What matters is that the base is *sent* rather than assumed.
    $operator = seededOperator();

    $operator->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt((string) json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($operator->refresh())
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'panel.path',
            '/'.trim((string) config('admin.path'), '/'),
        ));
});

it('shares nothing about the panel with a tenant page', function (): void {
    // One prop about an internal tool on a customer's screen is one more than belongs there.
    $member = createUserWithTeam();

    $this->actingAs($member)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('panel', null));
});

it('keeps every other control on the enrolment screen', function (): void {
    // It is the one route without the second-factor check, which is the point of it. It is not an
    // exception to anything else.
    $outsider = createUserWithTeam();

    // Not an operator: a 404, exactly as the rest of the panel answers — a 403 would confirm the
    // panel is there.
    $this->actingAs($outsider)->get(route('admin.security.edit'))->assertNotFound();

    // Not signed in: the sign-in page. (`actingAs` sticks for the rest of a test, so the guest
    // case has to put the session back first — one test, one acting credential.)
    auth()->logout();
    session()->flush();

    $this->get(route('admin.security.edit'))->assertRedirect('/login');

    // Not from a permitted network, when one is configured.
    config()->set('admin.ip_allowlist', ['203.0.113.0/24']);

    $operator = seededOperator();

    $this->actingAs($operator)->get(route('admin.security.edit'))->assertNotFound();
});

it('never removes tenant scoping on the enrolment screen', function (): void {
    // `PlatformContext` is deliberately absent: enrolment reads the operator's own account and
    // nothing else, so there is nothing here worth removing the tenancy scope for. The route
    // contract test asserts this from the route table as well; this says it in behaviour.
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($candidate): bool => $candidate->getName() === 'admin.security.edit');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())
        ->not->toContain(PlatformContext::class)
        ->and($route->gatherMiddleware())
        ->not->toContain(EnsureAdminTwoFactor::class);
});

it('lets a support operator enrol too, not only a super admin', function (): void {
    // The ability on the route is `access`, which every platform role holds. An operator who
    // cannot enrol is an operator who cannot work.
    $support = User::factory()->create(['email_verified_at' => now()]);
    $support->forceFill(['admin_role' => AdminRole::Support->value])->save();

    $this->actingAs($support->refresh())->get(route('admin.security.edit'))->assertOk();
});

it('does not insist when the environment has switched the requirement off', function (): void {
    config()->set('admin.require_two_factor', false);

    $operator = seededOperator();

    // The local convenience documented in the runbook: the panel opens, and the enrolment screen
    // says the requirement is not being enforced rather than pretending it is.
    $this->actingAs($operator)->get('/admin')->assertOk();
    $this->actingAs($operator)
        ->get(route('admin.security.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('required', false));
});
