<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Enums\ApiErrorCode;
use App\Modules\Core\Http\Responses\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps every `/api/v1` response with the version that served it, and refuses a request that
 * asks for a version this build does not speak (Phase 17).
 *
 * **The policy** (also written up in `docs/api/README.md`): the version is in the path, one
 * number, and it changes only for a change that would break a client already in the field —
 * removing a field, renaming one, narrowing what a parameter accepts, changing a status code.
 * Everything additive — a new endpoint, a new field on a resource, a new enum case, a new optional
 * filter — ships inside `v1`, so a mobile build from six months ago keeps working. That bargain
 * only holds if clients tolerate fields they do not know, which is the one thing asked of them in
 * return.
 *
 * `X-Api-Version` on the way **out** is the version that answered. On the way **in** it is
 * optional, and a client that sends it is pinning itself: a mismatch is a 400 rather than a
 * best-effort answer from the wrong contract, because a client that asked for v2 and silently got
 * v1 would misread every response it then parsed.
 */
final class ApiVersion
{
    /**
     * The version this mount serves. Bare number; `v1` is accepted on the way in for the client
     * that copies it out of the path.
     */
    public const string VERSION = '1';

    public const string HEADER = 'X-Api-Version';

    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->header(self::HEADER);

        if (is_string($requested) && $requested !== '' && ! $this->matches($requested)) {
            return ApiError::response(
                ApiErrorCode::UnsupportedApiVersion,
                __('This build serves API version :version. Point your client at /api/v:version.', [
                    'version' => self::VERSION,
                ]),
                Response::HTTP_BAD_REQUEST,
                ['requested' => $requested, 'supported' => [self::VERSION]],
            );
        }

        $response = $next($request);

        $response->headers->set(self::HEADER, self::VERSION);

        return $response;
    }

    private function matches(string $requested): bool
    {
        return ltrim(trim($requested), 'vV') === self::VERSION;
    }
}
