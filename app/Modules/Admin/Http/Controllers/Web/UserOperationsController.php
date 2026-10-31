<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Actions\ResetUserTwoFactorAction;
use App\Modules\Admin\Actions\RevokeUserSessionsAction;
use App\Modules\Admin\Actions\RevokeUserTokensAction;
use App\Modules\Admin\Actions\SendUserPasswordResetAction;
use App\Modules\Admin\Actions\VerifyUserEmailAction;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Support\ProtectsOperators;
use App\Modules\Core\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account operations an operator performs *for* somebody, as themselves (Phase 19a).
 *
 * The rule running through all of them: **an operator never knows or chooses a customer's
 * credential.** A password reset sends a link; it does not set a password. A two-factor reset clears
 * the secret so the person can enrol again; it does not reveal or replace it. Session and token
 * revocation take access away, which is safe to do on somebody's behalf, and never grant it.
 *
 * That is also why this is a bespoke screen rather than registry fields on `users`: every operation
 * here is a verb with a side effect, and a form that *looked* like it could edit `password` or
 * `email` would be an invitation to do the one thing the panel must not.
 *
 * Each one is an Action, not a `forceFill` here. Two reasons, both from CLAUDE.md §2: the write and
 * its audit entry have to be **one transaction**, or a failed audit leaves an unaudited 2FA reset;
 * and every one of them has to refuse when the target is another **operator**, which is a rule
 * about who may act on whom rather than a controller's business (see
 * {@see ProtectsOperators}).
 */
final class UserOperationsController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly AdminAudit $audit,
    ) {}

    public function show(User $user): Response
    {
        $this->audit->record(
            AdminAudit::RECORD_VIEWED,
            $user,
            ['resource' => 'users', 'intent' => 'operations'],
            $user->currentTeamId(),
        );

        return Inertia::render('admin/users/Show', [
            'navigation' => $this->registry->navigation(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'admin_role' => $user->adminRole()?->value,
                'admin_role_label' => $user->adminRole()?->label(),
                'verified' => $user->email_verified_at !== null,
                'two_factor' => $user->two_factor_confirmed_at !== null,
                'created_at' => $user->created_at?->toIso8601String(),
                'tokens' => $user->tokens()->count(),
            ],
            'teams' => $user->teams()->get()->map(static fn (Team $team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'role' => $team->getAttribute('pivot')?->getAttribute('role'),
                'suspended' => $team->isSuspended(),
            ])->values()->all(),
        ]);
    }

    public function sendPasswordReset(Request $request, User $user, SendUserPasswordResetAction $action): RedirectResponse
    {
        $status = $action->handle($user, $this->actor($request));

        // The broker's verdict, not an assumption. A throttled request sends nothing, and telling
        // the operator a link is on its way would have them tell the customer to watch an inbox
        // for a mail that was never sent.
        Inertia::flash('toast', $status === Password::RESET_LINK_SENT
            ? ['type' => 'success', 'message' => 'A reset link is on its way to '.$user->email.'.']
            : ['type' => 'error', 'message' => 'No link was sent: '.__($status)]);

        return to_route('admin.users.show', $user);
    }

    public function resetTwoFactor(Request $request, User $user, ResetUserTwoFactorAction $action): RedirectResponse
    {
        $action->handle($user, $this->actor($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Two-factor cleared. They will be asked to set it up again.',
        ]);

        return to_route('admin.users.show', $user);
    }

    public function revokeSessions(Request $request, User $user, RevokeUserSessionsAction $action): RedirectResponse
    {
        $swept = $action->handle($user, $this->actor($request));

        Inertia::flash('toast', [
            'type' => 'success',
            // The remember-me token is rotated either way, which is what makes this true even when
            // the driver keeps no server-side sessions to count.
            'message' => $swept > 0
                ? 'Signed out of '.$swept.' stored session(s), and the remember-me token is rotated.'
                : 'Remember-me token rotated. This session driver keeps no stored sessions to sweep.',
        ]);

        return to_route('admin.users.show', $user);
    }

    public function revokeTokens(Request $request, User $user, RevokeUserTokensAction $action): RedirectResponse
    {
        $count = $action->handle($user, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Revoked '.$count.' token(s).']);

        return to_route('admin.users.show', $user);
    }

    public function verifyEmail(Request $request, User $user, VerifyUserEmailAction $action): RedirectResponse
    {
        $changed = $action->handle($user, $this->actor($request));

        Inertia::flash('toast', $changed
            ? ['type' => 'success', 'message' => 'Address marked verified.']
            : ['type' => 'info', 'message' => 'That address was already verified.']);

        return to_route('admin.users.show', $user);
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}
