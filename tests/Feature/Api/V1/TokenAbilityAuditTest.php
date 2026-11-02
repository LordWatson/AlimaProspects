<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Str;

/*
 * The token-abilities audit (Phase 20, task 2).
 *
 * `RouteContractTest` (Phase 17) already asserts that every `/api/v1` route *has* a scope and that
 * the scope is one the enum defines. That leaves the question this file asks: is the scope the
 * **right kind**?
 *
 * The failure it exists to catch is a one-character one and completely silent. A route declared
 * `ability:leads:read,leads:write` passes every Phase 17 assertion — both abilities are real, the
 * route is scoped — and `EnsureTokenAbility` passes a token carrying *any one* of the listed
 * abilities. So a `POST` with a read ability in its list is a read-only token that can write, and
 * nothing would have said so.
 *
 * It also checks the other direction: an ability the picker offers but no route enforces is a
 * promise the settings screen makes and the API does not keep.
 */

uses(RefreshDatabase::class);

/**
 * Every ability named in a route's `ability:` middleware.
 *
 * @return list<string>
 */
function routeAbilities(RoutingRoute $route): array
{
    $abilities = [];

    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware) || ! Str::startsWith($middleware, 'ability:')) {
            continue;
        }

        foreach (explode(',', Str::after($middleware, 'ability:')) as $ability) {
            $abilities[] = trim($ability);
        }
    }

    return array_values(array_unique($abilities));
}

function isWriteRoute(RoutingRoute $route): bool
{
    return array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];
}

it('never lets a read-only ability satisfy a route that writes', function (): void {
    $offenders = apiRoutes()
        ->filter(fn (RoutingRoute $route): bool => isWriteRoute($route))
        ->map(fn (RoutingRoute $route): array => [
            'label' => routeLabel($route),
            'read' => array_values(array_filter(
                routeAbilities($route),
                static fn (string $ability): bool => Str::endsWith($ability, ':read'),
            )),
        ])
        ->filter(fn (array $row): bool => $row['read'] !== [])
        ->map(fn (array $row): string => $row['label'].' accepts '.implode(',', $row['read']))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('enforces every ability the token picker offers', function (): void {
    $enforced = apiRoutes()
        ->flatMap(fn (RoutingRoute $route): array => routeAbilities($route))
        ->unique()
        ->all();

    $unenforced = collect(TokenAbility::cases())
        ->reject(fn (TokenAbility $ability): bool => in_array($ability->value, $enforced, true))
        ->map(fn (TokenAbility $ability): string => $ability->value)
        ->all();

    // An ability on the settings screen that no route checks is a checkbox that does nothing —
    // and the person who ticked only the read boxes believes they have scoped the token.
    expect($unenforced)->toBe([]);
});

it('offers every enforced ability for issue', function (): void {
    $issuable = TokenAbility::issuable();

    $unissuable = apiRoutes()
        ->flatMap(fn (RoutingRoute $route): array => routeAbilities($route))
        ->unique()
        ->reject(fn (string $ability): bool => in_array($ability, $issuable, true))
        ->values()
        ->all();

    // The mirror image: a scope no token can be issued with is an endpoint nobody can ever reach.
    expect($unissuable)->toBe([]);
});

it('refuses a read-only token on a write endpoint and accepts a write one', function (): void {
    $user = createUserWithTeam();

    // One acting credential per test: Laravel resolves the guard once and keeps it, so swapping
    // tokens mid-test would silently keep the first one.
    $readOnly = $user->createToken('phone', [TokenAbility::LeadsRead->value])->plainTextToken;

    $this->withToken($readOnly)
        ->postJson('/api/v1/leads', ['name' => 'Acme Ltd'])
        ->assertForbidden()
        ->assertApiError('insufficient_token_ability');
});

it('accepts a write-scoped token on the same endpoint', function (): void {
    $user = createUserWithTeam();

    $write = $user->createToken('integration', [TokenAbility::LeadsWrite->value])->plainTextToken;

    // Not asserting a 201 — the point is only that the scope let the request through to the
    // controller, where validation and the quota guard take over.
    $this->withToken($write)
        ->postJson('/api/v1/leads', [])
        ->assertStatus(422);
});

it('lets the wildcard through', function (): void {
    $user = createUserWithTeam();

    // What Sanctum issues by default, and what a first-party caller effectively holds.
    $wildcard = $user->createToken('legacy', [TokenAbility::WILDCARD])->plainTextToken;

    $this->withToken($wildcard)
        ->getJson('/api/v1/leads')
        ->assertOk();
});
