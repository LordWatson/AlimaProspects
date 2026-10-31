<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Support\TeamContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Steps the request outside every tenant (Phase 19a). **The dangerous middleware.**
 *
 * The property it relies on is the one that makes this phase risky: {@see BelongsToTeam}'s global
 * scope is a **no-op when no team is active**. Forgetting the team therefore does not narrow
 * anything — it unscopes every tenant model in the product at once. That is exactly what an admin
 * index needs, and exactly what must never happen anywhere else, so two guards sit around it.
 *
 * **First, it refuses to run on a route that is not an admin route.** Applied by accident to the
 * leads list — in a global middleware group, say, or by a well-meant `->middleware()` on the wrong
 * line — this class would silently show every tenant's prospects to whoever asked. A 500 with an
 * explanation is the correct response to its own misuse, and `AdminRouteContractTest` asserts from
 * the other direction too: no route outside `admin.*` may carry it.
 *
 * **Second, it leaves a one-tenant read to {@see TeamContext::run()}.** Drilling into a team does
 * not get a second query path with hand-written `where team_id` clauses; it runs inside the tenant's
 * own context, so the admin sees exactly what that tenant sees, through the same scope and the same
 * policies. A bespoke path would be a second implementation of tenancy to keep in step with the
 * first, and the moment they disagreed the panel would be the one that was wrong.
 *
 * Spatie's team-scoped permission context is cleared alongside it, because `LogContext` set it from
 * the operator's own `current_team_id` on the way in and a stale value there would resolve roles
 * against a team the operator happens to belong to.
 */
final class PlatformContext
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly PermissionRegistrar $permissions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->assertAdminRoute($request);

        $this->teamContext->forget();
        $this->permissions->setPermissionsTeamId(null);

        return $next($request);
    }

    /**
     * @throws HttpException
     */
    private function assertAdminRoute(Request $request): void
    {
        $route = $request->route();
        $name = $route instanceof Route ? (string) $route->getName() : '';

        abort_unless(
            Str::startsWith($name, 'admin.'),
            500,
            'PlatformContext removes tenant scoping and may only be applied to admin.* routes. '
            .'It was reached on ['.($name !== '' ? $name : $request->path()).'].',
        );
    }
}
