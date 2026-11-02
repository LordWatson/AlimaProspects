<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Models\User;
use App\Modules\Admin\Services\Impersonator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door. Everything under `/admin` is behind it (Phase 19a).
 *
 * **404, not 403**, for somebody who is not an operator — including a signed-in customer who has
 * guessed the path. A 403 confirms there is something there; a 404 says the same thing a wrong URL
 * says. An operator who *is* one but lacks the ability for a particular route gets a 403 from
 * {@see EnsureAdminAbility} instead, which is the honest answer at that point: they know the panel
 * exists, they are in it, and the refusal is about this screen.
 *
 * **An impersonating session is refused too.** While impersonating, the operator *is* the customer,
 * and `Auth::user()` is the customer's account — which has no admin role, so the role check already
 * refuses. The explicit check is here anyway, because the day somebody impersonates another
 * operator (which {@see Impersonator} refuses) or an operator's own account gains a role mid-session
 * is the day an implicit refusal becomes a privilege escalation. The way back is the banner's "stop
 * impersonating" link, which is not an admin route.
 */
final class EnsurePlatformAdmin
{
    public function __construct(private readonly Impersonator $impersonator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isPlatformAdmin()) {
            abort(404);
        }

        if ($this->impersonator->isActive()) {
            abort(404);
        }

        return $next($request);
    }
}
