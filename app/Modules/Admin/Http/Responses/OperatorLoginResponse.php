<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Responses;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a login lands (Phase 19a, fixed after S10).
 *
 * Fortify's own response sends everybody to one configured path, `/dashboard`, which assumes every
 * account has a team. **A platform operator has none** — `SuperAdminSeeder` says so in as many
 * words, and that is the point of it: an operator belongs to no tenant, so nothing for a forgotten
 * team scope to quietly narrow. The dashboard resolves `currentTeam()->firstOrFail()`, so signing
 * in as the operator landed on a **404**: the panel was fine, the front door was not.
 *
 * So the rule is narrow and about the account rather than the role: **no current team, but a
 * platform role, means the panel**. An operator who also belongs to a team — the production advice
 * is to register normally and be promoted — keeps the product's dashboard, because they have one
 * to look at. Everybody else gets exactly Fortify's behaviour, `intended()` included, so a
 * deep-linked login still returns to where it was going.
 *
 * It is bound by `AdminServiceProvider`, which is how Admin reaches anything outside itself:
 * decorate the seam, do not make the other module know about operators (ADR 0014). `/admin` then
 * applies its own four controls — the enrolment redirect among them, which is why a fresh operator
 * still meets the security page first.
 */
final class OperatorLoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            // A token client has no landing page, and the shape of this answer is Fortify's
            // contract with the mobile app (Phase 18).
            return response()->json(['two_factor' => false]);
        }

        $panel = self::panelFor($request);

        return $panel === null
            ? redirect()->intended(Fortify::redirects('login'))
            : redirect()->to($panel);
    }

    /**
     * The panel's path, when this request's account has nowhere else to land.
     *
     * Shared with {@see OperatorTwoFactorLoginResponse} rather than written twice: a login with a
     * second factor and a login without one are the same question about the same account.
     */
    public static function panelFor(Request $request): ?string
    {
        $user = $request->user();

        if (! $user instanceof User || $user->current_team_id !== null) {
            return null;
        }

        if (! $user->isPlatformAdmin()) {
            return null;
        }

        return '/'.trim((string) config('admin.path', 'admin'), '/');
    }
}
