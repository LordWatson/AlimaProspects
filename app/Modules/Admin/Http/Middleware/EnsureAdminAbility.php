<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Models\User;
use App\Modules\Admin\Enums\AdminAbility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The per-route ability check: `admin.can:tenant.read` (Phase 19a).
 *
 * Middleware rather than a policy, for the reason ADR 0013 gives about token scopes: a policy
 * answers "may this person act on *this record*", and these questions are not about a record at
 * all. Whether an operator may read tenant data, impersonate, or turn Stripe off is a property of
 * the credential they are holding, and it has to be answerable before a controller has loaded
 * anything — most admin routes do not have a model to pass a policy.
 *
 * **One ability per route, and exactly one.** `AdminRouteContractTest` asserts it over the whole
 * route table: a route with none is an unguarded screen, and a route with two is a rule nobody can
 * read off the route file. An ability string the enum does not define is also a failure there —
 * otherwise a typo becomes a gate no role can ever satisfy, which is a 403 with no way out.
 */
final class EnsureAdminAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();
        $required = AdminAbility::tryFrom($ability);

        // A misspelled ability is a programming error, not a permission question. Refusing with a
        // 500 rather than a 403 is deliberate: a 403 would look like a role problem and send
        // somebody to change a config file that was never wrong.
        abort_if($required === null, 500, 'Unknown admin ability: '.$ability);

        if (! $user instanceof User || ! $user->hasAdminAbility($required)) {
            abort(403, 'Your platform role does not include: '.$required->label().'.');
        }

        return $next($request);
    }
}
