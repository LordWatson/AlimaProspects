<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Actions\AcceptInvitationAction;
use App\Modules\Auth\Actions\SwitchTeamAction;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Core\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing + accept for a team invitation. Reached via a temporary signed link, so lookups bypass
 * the tenant global scope and key on the invitation's secret token.
 */
final class InvitationAcceptanceController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = $this->resolve($token);

        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $invitation->team()->first();

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'team' => ['name' => $team->name],
            'role' => $invitation->roleEnum()->label(),
            'email' => $invitation->email,
            'emailMatches' => Str::of($user->email)->lower()->exactly(Str::of($invitation->email)->lower()->value()),
            'isExpired' => $invitation->isExpired(),
            'isAccepted' => $invitation->isAccepted(),
        ]);
    }

    public function accept(Request $request, string $token, AcceptInvitationAction $accept, SwitchTeamAction $switch): RedirectResponse
    {
        $invitation = $this->resolve($token);

        /** @var User $user */
        $user = $request->user();

        $team = $accept->handle($invitation, $user);

        // Drop the newcomer straight into the team they just joined.
        $switch->handle($user, $team);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Welcome to :team!', ['team' => $team->name])]);

        return to_route('dashboard');
    }

    private function resolve(string $token): TeamInvitation
    {
        /** @var TeamInvitation $invitation */
        $invitation = TeamInvitation::query()
            ->withoutGlobalScopes()
            ->where('token', $token)
            ->firstOrFail();

        return $invitation;
    }
}
