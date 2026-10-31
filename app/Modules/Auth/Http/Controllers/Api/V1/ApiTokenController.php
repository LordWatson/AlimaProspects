<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Actions\ExchangeCredentialsForTokenAction;
use App\Modules\Auth\Actions\RevokeApiTokenAction;
use App\Modules\Auth\Http\Requests\IssueApiTokenRequest;
use App\Modules\Auth\Http\Resources\ApiTokenResource;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Services\TwoFactorChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum token lifecycle for the mobile client. Issuance exchanges credentials for a token;
 * listing/revocation require an existing token (auth:sanctum).
 */
final class ApiTokenController extends Controller
{
    /**
     * Exchange email + password — and a second factor, where the account has one — for a personal
     * access token (guest, throttled).
     *
     * Two-factor accounts are answered in one request rather than Fortify's session-backed dance
     * (Phase 18): a client posts the credentials, gets a `423 auth.two_factor_required` naming
     * what it may send, and posts the lot again with `code` or `recovery_code`. See
     * {@see TwoFactorChallenge} for why that is the safe shape for a caller with no session, and
     * {@see ExchangeCredentialsForTokenAction} for why the challenge and the mint share one
     * transaction.
     */
    public function issue(IssueApiTokenRequest $request, ExchangeCredentialsForTokenAction $exchange): JsonResponse
    {
        $issued = $exchange->handle($request->exchange());

        return new JsonResponse([
            'token' => $issued->plainTextToken,
            'user' => UserResource::make($issued->user)->resolve($request),
        ], Response::HTTP_CREATED);
    }

    /**
     * List the authenticated user's tokens.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return ApiTokenResource::collection($user->tokens()->latest()->get());
    }

    /**
     * Revoke a specific token by id.
     */
    public function destroy(Request $request, string $token, RevokeApiTokenAction $revoke): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($revoke->handle($user, $token), Response::HTTP_NOT_FOUND);

        return new JsonResponse(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Revoke the token used for the current request.
     */
    public function destroyCurrent(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // On an auth:sanctum route the acting token is always a PersonalAccessToken.
        $user->currentAccessToken()->delete();

        return new JsonResponse(status: Response::HTTP_NO_CONTENT);
    }
}
