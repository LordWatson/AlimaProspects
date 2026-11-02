<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\Impersonator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hard rule: an impersonated session may not do anything that leaves the building or that the
 * customer cannot undo (Phase 19a).
 *
 * The list lives in `config('admin.impersonation.blocked_routes')` as **route-name patterns**, and
 * it is one list in one file rather than a middleware sprinkled across eight modules' route files.
 * That is deliberate: a per-module annotation would be eight places to forget, and "what can't an
 * operator do in somebody's account?" would be a question you answered by reading all of them.
 *
 * What is on it, and why each one:
 *
 * - **Outreach sending.** An operator in a customer's seat emailing a prospect is a message the
 *   customer did not write, from their domain, to a real person — and it would land in their
 *   suppression and consent record as though they had written it.
 * - **Billing.** A checkout, a plan swap or a cancellation spends or stops somebody's money. An
 *   operator who needs to change a plan does it as themselves, where the audit trail says so.
 * - **Erasure and export.** Irreversible, and a data-protection decision that belongs to the
 *   controller — which is the tenant, not us.
 * - **Credentials.** Passwords, email addresses, two-factor and API tokens. Changing any of them
 *   while wearing somebody's account is how an impersonation becomes a way to keep the account.
 * - **Account deletion**, for the obvious reason.
 *
 * Applied to the whole `web` group, because the routes it guards are the tenant's own. A blocked
 * route raises {@see AdminException::forbiddenWhileImpersonating()}, which the central renderer
 * turns into a toast and a bounce — the operator stays where they were and is told why, rather than
 * meeting a bare 403 and wondering whether the product is broken.
 */
final class RestrictImpersonatedSession
{
    public function __construct(private readonly Impersonator $impersonator) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->impersonator->isActive()) {
            return $next($request);
        }

        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;

        if ($this->impersonator->blocks($name)) {
            throw AdminException::forbiddenWhileImpersonating();
        }

        return $next($request);
    }
}
