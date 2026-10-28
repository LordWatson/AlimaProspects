<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Actions\IssueApiTokenAction;
use App\Modules\Auth\Actions\RevokeApiTokenAction;
use App\Modules\Auth\Http\Requests\IssueApiTokenRequest;
use App\Modules\Auth\Http\Resources\ApiTokenResource;
use App\Modules\Auth\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum token lifecycle for the mobile client. Issuance exchanges credentials for a token;
 * listing/revocation require an existing token (auth:sanctum).
 */
final class ApiTokenController extends Controller
{
    /**
     * Exchange email + password for a personal access token (guest, throttled).
     */
    public function issue(IssueApiTokenRequest $request, IssueApiTokenAction $issue): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user === null || ! Hash::check((string) $request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        // Full mobile two-factor token exchange lands in the Mobile phase (18); until then a 2FA
        // account must mint tokens from the web settings screen.
        if ($user->two_factor_confirmed_at !== null) {
            abort(Response::HTTP_LOCKED, 'Two-factor is enabled on this account. Create a token from the web app.');
        }

        /** @var list<string> $abilities */
        $abilities = $request->validated('abilities', ['*']);

        $plainText = $issue->handle($user, (string) $request->validated('device_name'), $abilities);

        return new JsonResponse([
            'token' => $plainText,
            'user' => UserResource::make($user)->resolve($request),
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
