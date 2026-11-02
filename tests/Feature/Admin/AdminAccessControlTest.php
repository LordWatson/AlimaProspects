<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Core\Models\Team;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\AdminFixtures as Fixtures;

uses(RefreshDatabase::class);

/*
 * Who may reach the panel, and who may do what inside it (Phase 19a).
 *
 * The first test is the one that matters most, and it is written as a sweep over the **whole route
 * table** rather than a list of screens: the risk is not that somebody guesses `/admin`, it is that
 * route 21 is added next month without the stack and nobody notices. A hand-written list of paths
 * would be exactly as out of date as the person who wrote it.
 */

/**
 * Every admin route, with its parameters filled in from real fixtures.
 *
 * @return list<array{string, string}> method and URL
 */
function adminRouteTargets(Team $team, int $userId, int $recordId): array
{
    $substitutions = [
        'team' => (string) $team->getKey(),
        'user' => (string) $userId,
        'member' => (string) $userId,
        'resource' => 'lead-tags',
        'id' => (string) $recordId,
    ];

    $targets = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! $route instanceof RoutingRoute) {
            continue;
        }

        if (! Str::startsWith((string) $route->getName(), 'admin.') || $route->methods() === ['HEAD']) {
            continue;
        }

        $uri = $route->uri();

        foreach ($substitutions as $name => $value) {
            $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], $value, $uri);
        }

        $targets[] = [$route->methods()[0], '/'.$uri];
    }

    return $targets;
}

it('sends a guest to sign in rather than answering', function (): void {
    $this->get('/admin')->assertRedirect(route('login'));
});

it('answers 404 to a signed-in customer on every admin route', function (): void {
    [$customer, $team] = Fixtures::tenant();
    $tag = LeadTag::factory()->create(['team_id' => $team->getKey(), 'name' => 'Warm']);

    // **404, not 403.** A 403 confirms there is something there; a 404 says what a wrong URL says.
    foreach (adminRouteTargets($team, (int) $customer->getKey(), (int) $tag->getKey()) as [$method, $url]) {
        $response = $this->actingAs($customer)->call($method, $url);

        expect($response->getStatusCode())
            ->toBe(404, $method.' '.$url.' answered '.$response->getStatusCode().' to a customer');
    }
});

it('sends an operator without two-factor to set it up', function (): void {
    $operator = Fixtures::operatorWithoutTwoFactor();

    // A redirect, not a refusal: they have nothing to ask anybody for, they have something to do.
    $this->actingAs($operator)->get('/admin')->assertRedirect(route('security.edit'));
});

it('lets a super admin in', function (): void {
    $this->actingAs(Fixtures::operator())
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Dashboard'));
});

it('lets every role read tenant data', function (): void {
    foreach (AdminRole::cases() as $role) {
        Fixtures::tenant();

        $this->actingAs(Fixtures::operator($role))
            ->get('/admin/resources/leads')
            ->assertOk();
    }
});

it('refuses writes to a read-only operator', function (): void {
    [, $team] = Fixtures::tenant();
    $tag = LeadTag::factory()->create(['team_id' => $team->getKey(), 'name' => 'Warm']);

    $readOnly = Fixtures::operator(AdminRole::ReadOnly);

    $this->actingAs($readOnly)->get('/admin/resources/lead-tags/'.$tag->getKey().'/edit')->assertForbidden();
    $this->actingAs($readOnly)->patch('/admin/resources/lead-tags/'.$tag->getKey(), ['name' => 'Hot'])->assertForbidden();
    $this->actingAs($readOnly)->delete('/admin/resources/lead-tags/'.$tag->getKey())->assertForbidden();

    expect($tag->refresh()->name)->toBe('Warm');
});

it('refuses deletes to support, which may write but not delete', function (): void {
    [, $team] = Fixtures::tenant();
    $tag = LeadTag::factory()->create(['team_id' => $team->getKey(), 'name' => 'Warm']);

    $support = Fixtures::operator(AdminRole::Support);

    // Support fixes records; it does not remove them. Writing is allowed, deleting is not — those
    // are separate abilities precisely so this distinction is expressible.
    $this->actingAs($support)
        ->patch('/admin/resources/lead-tags/'.$tag->getKey(), ['name' => 'Hot'])
        ->assertRedirect();

    $this->actingAs($support)->delete('/admin/resources/lead-tags/'.$tag->getKey())->assertForbidden();

    expect(LeadTag::query()->withoutGlobalScopes()->whereKey($tag->getKey())->exists())->toBeTrue();
});

it('refuses team management to finance', function (): void {
    [, $team] = Fixtures::tenant();

    $this->actingAs(Fixtures::operator(AdminRole::Finance))
        ->post('/admin/teams/'.$team->getKey().'/suspend', ['reason' => 'Non-payment, chased twice.'])
        ->assertForbidden();

    expect($team->refresh()->isSuspended())->toBeFalse();
});

it('refuses impersonation to finance and read-only', function (): void {
    [$customer] = Fixtures::tenant();

    foreach ([AdminRole::Finance, AdminRole::ReadOnly] as $role) {
        $this->actingAs(Fixtures::operator($role))
            ->post('/admin/users/'.$customer->getKey().'/impersonate')
            ->assertForbidden();
    }
});

it('404s an unknown resource rather than failing somewhere deeper', function (): void {
    $this->actingAs(Fixtures::operator())
        ->get('/admin/resources/not-a-resource')
        ->assertNotFound();
});
