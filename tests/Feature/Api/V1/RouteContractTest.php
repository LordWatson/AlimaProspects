<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Core\Http\Middleware\ApiVersion;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * The properties that have to hold for **every** `/api/v1` route, asserted over the route table
 * rather than endpoint by endpoint (Phase 17).
 *
 * This is the file that makes the audit stick. A per-endpoint test only covers the endpoints
 * somebody remembered to write a test for; the next person to add a route and forget the scope,
 * the name or the documentation finds out here instead of in the mobile app.
 */

/**
 * @return Collection<int, RoutingRoute>
 */
function apiRoutes(): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => Str::startsWith($route->uri(), 'api/'))
        // HEAD duplicates GET.
        ->filter(fn (RoutingRoute $route): bool => $route->methods() !== ['HEAD'])
        ->values();
}

function routeLabel(RoutingRoute $route): string
{
    return implode('|', $route->methods()).' '.$route->uri();
}

it('serves nothing outside the versioned prefix', function (): void {
    // An unversioned route would sit outside the version policy, the error envelope and the
    // scopes all at once. `routes/api.php` is empty for that reason.
    $unversioned = apiRoutes()
        ->reject(fn (RoutingRoute $route): bool => Str::startsWith($route->uri(), 'api/v1/'))
        ->map(fn (RoutingRoute $route): string => routeLabel($route))
        ->all();

    expect($unversioned)->toBe([]);
});

it('routes everything through a controller, never a closure', function (): void {
    // A closure route has no FormRequest, no policy call and nothing for the documentation
    // generator to read — it is an endpoint that cannot be audited.
    $closures = apiRoutes()
        ->filter(fn (RoutingRoute $route): bool => $route->getAction('uses') instanceof Closure)
        ->map(fn (RoutingRoute $route): string => routeLabel($route))
        ->all();

    expect($closures)->toBe([]);
});

it('names every route under the api.v1 prefix', function (): void {
    // The name is how tests and clients address an endpoint without hard-coding its path.
    $unnamed = apiRoutes()
        ->reject(fn (RoutingRoute $route): bool => Str::startsWith((string) $route->getName(), 'api.v1.'))
        ->map(fn (RoutingRoute $route): string => routeLabel($route))
        ->all();

    expect($unnamed)->toBe([]);
});

it('puts every route behind Sanctum but the token exchange', function (): void {
    $open = apiRoutes()
        ->reject(fn (RoutingRoute $route): bool => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $route): string => (string) $route->getName())
        ->all();

    // The only guest endpoint: credentials in, token out, hard-throttled.
    expect($open)->toBe(['api.v1.auth.token.issue']);
});

it('throttles and version-stamps every route', function (): void {
    apiRoutes()->each(function (RoutingRoute $route): void {
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('throttle:api', ApiVersion::class);
    });
});

it('declares a token scope on every route that handles team data', function (): void {
    // The exceptions, and why: identity and a token's own lifecycle cannot be scoped away without
    // creating credentials nobody can audit or revoke, and the token exchange has no token yet.
    $unscopable = [
        'api.v1.auth.token.issue',
        'api.v1.auth.token.current',
        'api.v1.auth.tokens.index',
        'api.v1.auth.tokens.destroy',
        'api.v1.user.show',
    ];

    $missing = apiRoutes()
        ->reject(fn (RoutingRoute $route): bool => in_array($route->getName(), $unscopable, true))
        ->reject(fn (RoutingRoute $route): bool => collect($route->gatherMiddleware())
            ->contains(fn (mixed $middleware): bool => is_string($middleware) && Str::startsWith($middleware, 'ability:')))
        ->map(fn (RoutingRoute $route): string => routeLabel($route))
        ->all();

    expect($missing)->toBe([]);
});

it('scopes every route with an ability the enum defines', function (): void {
    $known = TokenAbility::issuable();
    $unknown = [];

    foreach (apiRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! Str::startsWith($middleware, 'ability:')) {
                continue;
            }

            foreach (explode(',', Str::after($middleware, 'ability:')) as $ability) {
                if (! in_array($ability, $known, true)) {
                    $unknown[] = routeLabel($route).' => '.$ability;
                }
            }
        }
    }

    // An ability nothing issues is a scope no token can ever satisfy — an endpoint bricked by a
    // typo, and a 403 with no way out.
    expect($unknown)->toBe([]);
});

it('keeps the committed OpenAPI document in step with the routes', function (): void {
    $path = base_path('docs/api/openapi.json');

    expect($path)->toBeReadableFile();

    /** @var array{paths: array<string, array<string, mixed>>} $document */
    $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    $undocumented = apiRoutes()
        ->reject(function (RoutingRoute $route) use ($document): bool {
            // The document's paths are relative to the `/api/v1` server, with `{param}` kept.
            $path = '/'.Str::after($route->uri(), 'api/v1/');

            foreach ($route->methods() as $method) {
                if (isset($document['paths'][$path][strtolower($method)])) {
                    return true;
                }
            }

            return false;
        })
        ->map(fn (RoutingRoute $route): string => routeLabel($route))
        ->all();

    // Regenerate with `composer api:docs`. A specification that silently lags the routes is worse
    // than none: a client generated from it is wrong in a way nothing reports.
    expect($undocumented)->toBe([]);
});
