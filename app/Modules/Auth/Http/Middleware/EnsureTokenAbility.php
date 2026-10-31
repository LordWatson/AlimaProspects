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
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the bearer token behind the request to carry **at least one** of the given abilities
 * (Phase 17), aliased as `ability` and declared per route:
 *
 * ```php
 * Route::get('leads', ...)->middleware('ability:'.TokenAbility::LeadsRead->value);
 * ```
 *
 * **Scopes are a property of the bearer credential, not of the person.** A first-party caller —
 * a session cookie, which is how the web app and the Inertia screens reach anything — presents no
 * bearer token, and is let through: the browser session is already trusted at the full trust level
 * of an app that has no scopes at all, so refusing it here would make the same member *less*
 * trusted through `/api/v1` than through a page. What a session may do is decided where it has
 * always been decided, by the policies the controllers call.
 *
 * Whether a token was presented is read the way Sanctum itself reads it, through
 * `Sanctum::$accessTokenRetrievalCallback`, so a deployment that customises where the token comes
 * from cannot end up with a guard that looks somewhere else.
 *
 * Sanctum ships `CheckForAnyAbility`, which this deliberately replaces: it rejects the
 * cookie-authenticated caller outright, and it aborts with a bare 403 outside the error envelope
 * every client is written against ({@see ApiError}).
 *
 * **Testing a scope needs a real token.** `Sanctum::actingAs()` sets a token object on the user but
 * sends no credential with the request, so it reads as first-party here; a test that means to
 * exercise a scope issues a token and sends it as `Authorization: Bearer …`
 * (see `tests/Feature/Api/V1/TokenAbilityTest.php`).
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

        if ($this->presentedToken($request) === '') {
            return $next($request);
        }

        foreach ($abilities as $ability) {
            if ($user->tokenCan($ability)) {
                return $next($request);
            }
        }

        throw TokenAbilityException::missing(array_values($abilities));
    }

    /**
     * The bearer credential on this request, read exactly as Sanctum's guard reads it. Empty
     * string when there is none.
     */
    private function presentedToken(Request $request): string
    {
        if (is_callable(Sanctum::$accessTokenRetrievalCallback)) {
            return (string) (Sanctum::$accessTokenRetrievalCallback)($request);
        }

        return (string) $request->bearerToken();
    }
}
