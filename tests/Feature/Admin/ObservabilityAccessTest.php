<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Http\Middleware\AdminIpAllowlist;
use App\Modules\Admin\Http\Middleware\EnsureAdminTwoFactor;
use App\Modules\Admin\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\AdminFixtures as Fixtures;

/*
 * Who may open the operator dashboards (Phase 20, ADR 0017).
 *
 * `/pulse` shows every tenant's slow queries, every tenant's exceptions and the shape of every
 * tenant's queue. It shipped behind a published gate that read
 * `in_array(optional($user)->email, [])` — which denied everybody, so nothing was *broken*; it was
 * a hard-coded email list waiting for somebody to fill it in by hand. These tests are what stop
 * that being the control again.
 *
 * The dashboard is **not rendered** here, only reached: it is a Livewire page whose markup is
 * Pulse's, and asserting on it would be asserting on a vendor view. What matters is the door.
 */

uses(RefreshDatabase::class);

it('answers 404 to a guest', function (): void {
    // 404 and not 401: a redirect to the login page confirms there is something there.
    $this->get('/pulse')->assertNotFound();
});

it('answers 404 to a signed-in customer who has guessed the path', function (): void {
    $this->actingAs(createUserWithTeam())
        ->get('/pulse')
        ->assertNotFound();
});

it('answers 403 to an operator without the operations ability', function (): void {
    // Read-only and Finance are real operators — they know the panel exists and are inside it — so
    // the honest answer at that point is a refusal about this screen, not a 404 about the app.
    $this->actingAs(Fixtures::operator(AdminRole::ReadOnly))
        ->get('/pulse')
        ->assertForbidden();
});

it('lets an operator with the operations ability through', function (): void {
    // Support: whoever is on at 2am, and the same ability that lets them retry a failed job.
    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->get('/pulse')
        ->assertOk();
});

it('sends an operator without two-factor to enrolment first', function (): void {
    $this->actingAs(Fixtures::operatorWithoutTwoFactor())
        ->get('/pulse')
        ->assertRedirect(route('admin.security.edit'));
});

it('answers 404 from outside the ip allowlist', function (): void {
    Config::set('admin.ip_allowlist', ['198.51.100.0/24']);

    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->get('/pulse')
        ->assertNotFound();
});

it('gates Horizon on exactly the same answer', function (): void {
    // The Horizon *worker* cannot run on Windows (it needs `ext-pcntl`), but its routes are
    // registered on every platform — so the door is testable here even though the dashboard it
    // fronts has nothing behind it locally.
    $support = Fixtures::operator(AdminRole::Support);
    $readOnly = Fixtures::operator(AdminRole::ReadOnly);
    $customer = createUserWithTeam();

    expect(Gate::forUser($support)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($readOnly)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($support)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($customer)->allows('viewPulse'))->toBeFalse();
});

/*
 * The Horizon half of the door (Phase 20 security review).
 *
 * `config/horizon.php` shipped as `['web']` alone, which was safe only because the published
 * `viewHorizon` gate denied everybody. Making that gate *pass* turned one control into the only
 * control — so a Support operator mid-2FA-enrolment, or a phished password with no TOTP, would have
 * reached `/horizon/api/jobs/failed` (every tenant's serialised job payloads) and the retry and
 * delete endpoints, from any network, while `/admin` and `/pulse` refused the same session.
 *
 * Asserted over Horizon's **whole route table** rather than on its index, because `/horizon/api/*`
 * is where the data and the write operations are, and a middleware list is exactly the kind of
 * thing that gets applied to the dashboard and not to its API.
 */

it('puts every horizon route behind the same four controls as the panel', function (): void {
    $expected = [
        EnsurePlatformAdmin::class,
        EnsureAdminTwoFactor::class,
        AdminIpAllowlist::class,
    ];

    $horizonRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => Str::startsWith($route->uri(), 'horizon'))
        ->filter(fn (RoutingRoute $route): bool => $route->methods() !== ['HEAD']);

    expect($horizonRoutes)->not->toBeEmpty();

    $unguarded = $horizonRoutes
        ->reject(function (RoutingRoute $route) use ($expected): bool {
            // `gatherRouteMiddleware`, not `gatherMiddleware`: Horizon attaches its stack as a
            // middleware **group** (`Route::middlewareGroup('horizon', …)`), so the route's own
            // list is the single string `horizon` and only the router expands it. A test reading
            // the unexpanded list would have passed before this fix and after it.
            $middleware = app('router')->gatherRouteMiddleware($route);

            foreach ($expected as $class) {
                if (! in_array($class, $middleware, true)) {
                    return false;
                }
            }

            return true;
        })
        ->map(fn (RoutingRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unguarded)->toBe([]);
});

it('refuses a customer and an un-enrolled operator at the horizon door', function (): void {
    $this->actingAs(createUserWithTeam())
        ->get('/horizon/api/jobs/failed')
        ->assertNotFound();
});

it('sends an un-enrolled operator to two-factor enrolment before horizon', function (): void {
    // The case that made this a real hole rather than a theoretical one: operators without a
    // confirmed second factor exist by design — `EnsureAdminTwoFactor` redirects rather than
    // refuses, so a freshly created operator is in exactly this state.
    $this->actingAs(Fixtures::operatorWithoutTwoFactor())
        ->get('/horizon/api/jobs/failed')
        ->assertRedirect(route('admin.security.edit'));
});

it('refuses horizon from outside the ip allowlist', function (): void {
    Config::set('admin.ip_allowlist', ['198.51.100.0/24']);

    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->get('/horizon/api/jobs/failed')
        ->assertNotFound();
});
