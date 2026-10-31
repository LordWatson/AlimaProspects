<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A platform operator must have confirmed two-factor authentication (Phase 19a).
 *
 * One super-admin credential reads every tenant's data in the product. A password alone is not the
 * right lock on that, and this is far cheaper to enforce now than to retrofit over an ops team that
 * has got used to not having it.
 *
 * Enforced as a **redirect to enrolment**, not a refusal: an operator without 2FA has nothing to go
 * and ask anybody for, they have a thing to go and do, and the security page is where they do it.
 * The freshly seeded `super@admin.com` therefore lands on enrolment on its first visit, which is
 * the correct local experience and is written down in the runbook rather than excepted here.
 *
 * It can be switched off with `ADMIN_REQUIRE_TWO_FACTOR=false`, and that exists for exactly one
 * reason: a test suite and a first-run-through of the panel should not have to solve a TOTP
 * challenge. It is not a deployment option — `.env.example` says so, and Phase 20's hardening pass
 * should assert it on in production.
 */
final class EnsureAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('admin.require_two_factor', true)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User && $user->two_factor_confirmed_at !== null) {
            return $next($request);
        }

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => 'Set up two-factor authentication before using the admin panel.',
        ]);

        return Redirect::route('security.edit');
    }
}
