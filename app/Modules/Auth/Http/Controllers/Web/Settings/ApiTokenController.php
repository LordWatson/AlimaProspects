<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Actions\IssueApiTokenAction;
use App\Modules\Auth\Actions\RevokeApiTokenAction;
use App\Modules\Auth\Http\Requests\CreateApiTokenRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Web UI for a signed-in user to manage their own Sanctum tokens (used by the mobile app).
 * The plaintext token is shown exactly once, right after creation.
 */
final class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/ApiTokens', [
            'tokens' => $user->tokens()
                ->latest()
                ->get()
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities ?? ['*'],
                    'last_used_at' => $token->last_used_at?->diffForHumans(),
                    'created_at' => $token->created_at?->toDayDateTimeString(),
                ])
                ->all(),
            // One-time plaintext value flashed from store().
            'newToken' => $request->session()->get('newApiToken'),
        ]);
    }

    public function store(CreateApiTokenRequest $request, IssueApiTokenAction $issue): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var list<string> $abilities */
        $abilities = $request->validated('abilities', ['*']);

        $plainText = $issue->handle($user, (string) $request->validated('name'), $abilities);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token created.')]);

        return to_route('api-tokens.index')->with('newApiToken', $plainText);
    }

    public function destroy(Request $request, string $token, RevokeApiTokenAction $revoke): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $revoke->handle($user, $token);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token revoked.')]);

        return back();
    }
}
