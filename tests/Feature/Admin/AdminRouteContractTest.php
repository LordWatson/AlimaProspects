<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminAbility;
use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Http\Middleware\AdminIpAllowlist;
use App\Modules\Admin\Http\Middleware\EnsureAdminTwoFactor;
use App\Modules\Admin\Http\Middleware\EnsurePlatformAdmin;
use App\Modules\Admin\Http\Middleware\PlatformContext;
use App\Modules\Admin\Resources\AdminResource;
use App\Modules\Admin\Resources\ResourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * The properties that have to hold for **every** admin route, asserted over the route table rather
 * than screen by screen (Phase 19a, Phase 17's precedent).
 *
 * This file is the reason the panel's isolation is worth trusting. `PlatformContext` removes tenant
 * scoping, and `BelongsToTeam`'s global scope is a no-op when no team is active — so one route that
 * carries that middleware without the rest of the stack is not a missing permission check, it is
 * every tenant's data served to whoever asked. A per-route test only covers the routes somebody
 * remembered to write one for; this covers the one they forgot.
 */

// The registry assertions read the real schema with `Schema::hasColumn`, which is the whole point
// of them — a descriptor is data, so the only thing that can be wrong with one is that it names a
// column nobody created.
uses(RefreshDatabase::class);

/**
 * @return Collection<int, RoutingRoute>
 */
function adminRoutes(): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => Str::startsWith((string) $route->getName(), 'admin.'))
        // HEAD duplicates GET.
        ->filter(fn (RoutingRoute $route): bool => $route->methods() !== ['HEAD'])
        ->values();
}

function adminRouteLabel(RoutingRoute $route): string
{
    return implode('|', $route->methods()).' '.$route->uri();
}

it('registers some admin routes at all', function (): void {
    // A guard on the guards: every assertion below passes vacuously over an empty collection, so a
    // rename of the `admin.` prefix would otherwise turn this whole file green and meaningless.
    expect(adminRoutes())->not->toBeEmpty();
});

it('puts the whole admin stack on every admin route', function (): void {
    $required = [
        'auth',
        EnsurePlatformAdmin::class,
        AdminIpAllowlist::class,
        EnsureAdminTwoFactor::class,
        PlatformContext::class,
        'throttle:admin',
    ];

    $missing = [];

    foreach (adminRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        foreach ($required as $layer) {
            if (! in_array($layer, $middleware, true)) {
                $missing[] = adminRouteLabel($route).' is missing '.$layer;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('never applies the platform context outside the admin routes', function (): void {
    // The assertion from the other direction, and the more important one. Applied by accident to
    // the leads list — in a global group, or on the wrong line — PlatformContext would silently
    // show every tenant's prospects to whoever asked.
    $leaked = collect(Route::getRoutes()->getRoutes())
        ->reject(fn (RoutingRoute $route): bool => Str::startsWith((string) $route->getName(), 'admin.'))
        ->filter(fn (RoutingRoute $route): bool => in_array(PlatformContext::class, $route->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $route): string => adminRouteLabel($route))
        ->values()
        ->all();

    expect($leaked)->toBe([]);
});

it('declares exactly one known ability on every admin route', function (): void {
    $problems = [];
    $known = AdminAbility::values();

    foreach (adminRoutes() as $route) {
        $abilities = collect($route->gatherMiddleware())
            ->filter(fn (mixed $m): bool => is_string($m) && Str::startsWith($m, 'admin.can:'))
            ->map(fn (string $m): string => Str::after($m, 'admin.can:'))
            ->values();

        if ($abilities->count() !== 1) {
            // None is an unguarded screen; two is a rule nobody can read off the route file.
            $problems[] = adminRouteLabel($route).' declares '.$abilities->count().' abilities';

            continue;
        }

        if (! in_array($abilities->first(), $known, true)) {
            // An ability the enum does not define is a gate nothing answers — a 403 with no way out.
            $problems[] = adminRouteLabel($route).' declares unknown ability '.$abilities->first();
        }
    }

    expect($problems)->toBe([]);
});

it('keeps the admin stack in the order the refusals depend on', function (): void {
    // Membership is not enough: the stack's **order** carries two behaviours.
    //
    //  - `EnsurePlatformAdmin` must precede `EnsureAdminTwoFactor`, or a signed-in customer who
    //    guesses the path gets redirected to a security page instead of the 404 that tells them
    //    nothing.
    //  - `PlatformContext` must follow `EnsurePlatformAdmin`, so tenant scoping is never removed
    //    for a request that is about to be refused anyway.
    $problems = [];

    foreach (adminRoutes() as $route) {
        $middleware = array_values($route->gatherMiddleware());
        $position = static fn (string $layer): int|false => array_search($layer, $middleware, true);

        $admin = $position(EnsurePlatformAdmin::class);
        $twoFactor = $position(EnsureAdminTwoFactor::class);
        $context = $position(PlatformContext::class);

        if ($admin === false || $twoFactor === false || $context === false) {
            continue; // Reported by the stack test above.
        }

        if ($admin > $twoFactor) {
            $problems[] = adminRouteLabel($route).' checks two-factor before the admin role';
        }

        if ($admin > $context) {
            $problems[] = adminRouteLabel($route).' clears tenant scoping before checking the role';
        }
    }

    expect($problems)->toBe([]);
});

it('keeps a non-default resource ability to the two resources that need one', function (): void {
    // A resource's own ability is checked *in addition to* its route's, so overriding it is how a
    // row that is not a customer's record gets a permission that is not `tenant.write`. Pinning the
    // list means a third override is a conversation rather than a quiet diff — in either direction:
    // dropping one of these would hand a support engineer the AI prompt registry.
    $overridden = [];

    foreach (app(ResourceRegistry::class)->all() as $key => $resource) {
        if ($resource->ability() !== AdminAbility::ReadTenantData) {
            $overridden[$key] = $resource->ability()->value;
        }
    }

    ksort($overridden);

    expect($overridden)->toBe([
        // Phase 19b added three more, all for the same reason as the prompt registry: the rows are
        // **ours** rather than a tenant's, so reading or changing them is not a support task.
        'ai-prompt-templates' => AdminAbility::ManagePlatformData->value,
        'announcements' => AdminAbility::ManagePlatformData->value,
        'audit-logs' => AdminAbility::ReadAudit->value,
        'platform-settings' => AdminAbility::ManagePlatformData->value,
    ]);
});

it('never lets a role write a resource whose ability it lacks', function (): void {
    // The property the two assertions above exist to protect, stated once: for every resource that
    // permits a write, the ability it declares has to be one a role carrying `tenant.write` either
    // holds or deliberately does not. Any role that can reach the write route but not the resource
    // is a 403 by design — this asserts the *pairing is declared*, not that it is permissive.
    $problems = [];

    foreach (app(ResourceRegistry::class)->all() as $key => $resource) {
        if (! $resource->allowsCreate() && ! $resource->allowsUpdate() && ! $resource->allowsDelete()) {
            continue;
        }

        if (! in_array($resource->ability(), AdminAbility::cases(), strict: true)) {
            $problems[] = $key.' declares an ability the enum does not define';
        }

        if (! AdminRole::SuperAdmin->can($resource->ability())) {
            // If the super admin cannot satisfy it, nobody can, and the resource is writable only
            // in the descriptor's imagination.
            $problems[] = $key.' permits writes behind an ability no role holds';
        }
    }

    expect($problems)->toBe([]);
});

it('names every admin route and routes it through a controller', function (): void {
    $closures = adminRoutes()
        ->filter(fn (RoutingRoute $route): bool => $route->getAction('uses') instanceof Closure)
        ->map(fn (RoutingRoute $route): string => adminRouteLabel($route))
        ->all();

    expect($closures)->toBe([]);
});

it('exposes no admin surface on the API', function (): void {
    // Web only, by decision: a token that can read every tenant is a liability no MVP ops task
    // needs, and it would have to be scoped, versioned and specified like everything else in
    // ADR 0013. The Admin module ships no routes/api.php at all.
    $api = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => Str::startsWith($route->uri(), 'api/'))
        ->filter(fn (RoutingRoute $route): bool => Str::contains((string) $route->getName(), 'admin')
            || Str::contains($route->uri(), 'admin'))
        ->map(fn (RoutingRoute $route): string => adminRouteLabel($route))
        ->all();

    expect($api)->toBe([]);
});

/*
 * The registry's own contract. A descriptor is data, so the only thing that can be wrong with one
 * is that it names something that does not exist — which no amount of type checking catches.
 */

it('declares only columns and fields that exist on the resource table', function (): void {
    $problems = [];

    foreach (app(ResourceRegistry::class)->all() as $key => $resource) {
        $table = $resource->table();

        $names = collect([...$resource->columns(), ...$resource->detailColumns()])
            // A dotted column reads an eager-loaded relation, which `Schema::hasColumn` cannot
            // check — those are covered by the eager-load assertion below instead.
            ->reject(fn ($column): bool => $column->isRelational())
            ->map(fn ($column): string => $column->name)
            ->merge(collect($resource->fields())->map(fn ($field): string => $field->name))
            ->merge($resource->searchable())
            ->merge(collect($resource->filters())->map(fn ($filter): string => $filter->name))
            ->merge($resource->sortable())
            ->push($resource->defaultSort())
            ->unique();

        foreach ($names as $name) {
            if (! Schema::hasColumn($table, $name)) {
                $problems[] = $key.' names missing column '.$table.'.'.$name;
            }
        }
    }

    expect($problems)->toBe([]);
});

it('eager loads the relation behind every dotted column', function (): void {
    // The index renders a page of rows, so a lazy relation is an N+1 — and with
    // `preventLazyLoading` on outside production it is an exception, not a slow page.
    $problems = [];

    foreach (app(ResourceRegistry::class)->all() as $key => $resource) {
        foreach ($resource->columns() as $column) {
            if (! $column->isRelational()) {
                continue;
            }

            $relation = Str::before($column->name, '.');

            if (! in_array($relation, $resource->with(), true)) {
                $problems[] = $key.' reads '.$column->name.' without eager loading '.$relation;
            }
        }
    }

    expect($problems)->toBe([]);
});

it('keeps generic delete switched on for only the resources that chose it', function (): void {
    // Delete is the one generic operation no module Action is watching, so the list of resources
    // that permit it is small enough to write down — and a change to it should be a failing test
    // and a conversation, not a quiet diff in a descriptor.
    $deletable = collect(app(ResourceRegistry::class)->all())
        ->filter(fn (AdminResource $resource): bool => $resource->allowsDelete())
        ->keys()
        ->sort()
        ->values()
        ->all();

    expect($deletable)->toBe(['lead-tags', 'push-devices', 'saved-views']);
});

it('never offers a write on a resource that declares no fields', function (): void {
    $problems = [];

    foreach (app(ResourceRegistry::class)->all() as $key => $resource) {
        if (($resource->allowsCreate() || $resource->allowsUpdate()) && $resource->fields() === []) {
            $problems[] = $key.' permits writes but declares no editable fields';
        }
    }

    expect($problems)->toBe([]);
});
