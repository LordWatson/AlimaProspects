<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Models\User;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Auth\Exceptions\TokenAbilityException;
use App\Modules\Core\Http\Responses\ApiError;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the credential behind the request to carry **at least one** of the given abilities
 * (Phase 17), aliased as `ability` and declared per route:
 *
 * ```php
 * Route::get('leads', ...)->middleware('ability:'.TokenAbility::LeadsRead->value);
 * ```
 *
 * **The decision is the credential's, and it is `tokenCan()` that asks it.** Sanctum hands the
 * user one of exactly three things, and that one call gives each the right answer:
 *
 * - a stored **personal access token** — the case scopes exist for, so its own abilities decide
 *   (and a token issued with `*` satisfies everything);
 * - a **`TransientToken`** — a first-party session, which is how the web app and the Inertia
 *   screens reach anything. It answers `true`, which is correct: a browser session is already
 *   trusted at the full trust level of an app that has no scopes at all, so refusing it here would
 *   make the same member *less* trusted through `/api/v1` than through a page. What a session may
 *   do is decided where it has always been decided, by the policies the controllers call;
 * - **nothing at all** — no credential, so no trust, and `tokenCan()` answers `false`.
 *
 * An earlier cut read the bearer header instead and treated its absence as first-party. It had no
 * live bypass, but it put the fail-open case on the common path, and it made every scope exercised
 * through `Sanctum::actingAs()` — which sets a token object and sends no header — pass untested.
 * Asking the credential closes both: a test that means to exercise a scope either names it in
 * `Sanctum::actingAs($user, [...])` or sends a real token, and a test that just means "a signed-in
 * member" uses `actingAs($user)`, which is the session path the web app actually takes.
 *
 * Sanctum ships `CheckForAnyAbility`, which this deliberately replaces: it rejects the
 * session-authenticated caller outright, and it aborts with a bare 403 outside the error envelope
 * every client is written against ({@see ApiError}).
 */
final class EnsureTokenAbility
{
    /**
     * @param  string  ...$abilities  Any one of them is enough ({@see TokenAbility}).
     *
     * @throws AuthenticationException|TokenAbilityException
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        foreach ($abilities as $ability) {
            if ($user->tokenCan($ability)) {
                return $next($request);
            }
        }

        throw TokenAbilityException::missing(array_values($abilities));
    }
}
