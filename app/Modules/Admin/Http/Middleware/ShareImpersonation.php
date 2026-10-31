<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Services\Impersonator;
use App\Modules\Core\Support\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes an impersonated session visible and finite, on **every** web request (Phase 19a).
 *
 * Appended to the `web` group rather than to the admin stack, which is the whole point: the screens
 * an impersonated operator is looking at are the *tenant's* screens, so anything that only ran under
 * `/admin` would be running nowhere that matters. It does three things, in this order:
 *
 * 1. **Expires the session**, handing the operator back to themselves. A forgotten impersonation
 *    is a customer's account left open in somebody's browser, so the clock ends it even if nobody
 *    clicks "stop" — and it ends it *without* calling `Auth::logout()`, which at that moment would
 *    rotate the customer's own remember-me token. See {@see Impersonator::expire()}.
 * 2. **Annotates the audit context**, so every write the session makes — through whichever module's
 *    Action, none of which know impersonation exists — records the operator behind the customer.
 *    See {@see AuditContext}.
 * 3. **Shares the banner prop**, so there is no tenant screen on which an operator can be inside
 *    somebody's account without the page saying so.
 *
 * It runs on guest requests too and costs nothing there: an empty session has no operator id, so
 * every branch below short-circuits before touching the database.
 */
final class ShareImpersonation
{
    public function __construct(private readonly Impersonator $impersonator) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->impersonator->isActive()) {
            return $next($request);
        }

        if ($this->impersonator->hasExpired()) {
            $operator = $this->impersonator->expire();

            if ($operator === null) {
                Inertia::flash('toast', [
                    'type' => 'error',
                    'message' => 'That impersonation session expired and its operator account no longer exists.',
                ]);

                return Redirect::route('login');
            }

            Inertia::flash('toast', [
                'type' => 'info',
                'message' => 'The impersonation session expired. You are back as '.$operator->name.'.',
            ]);

            return Redirect::route('admin.dashboard');
        }

        $this->impersonator->annotateAudit();

        // A closure, so the lookup it does is paid only on a full page load and skipped on the
        // partial reloads that do not re-render the shell — the same reason `translations` and
        // `csrfToken` are lazy in HandleInertiaRequests.
        Inertia::share('impersonation', fn (): ?array => $this->impersonator->banner());

        return $next($request);
    }
}
