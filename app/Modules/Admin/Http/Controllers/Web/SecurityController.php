<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\User;
use App\Modules\Admin\Http\Middleware\EnsureAdminTwoFactor;
use App\Modules\Admin\Resources\ResourceRegistry;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

/**
 * Enrolling the second factor the panel insists on — **inside the panel** (Phase 19a, fixed later).
 *
 * The panel has required two-factor authentication since 19a, and it was right to: one super-admin
 * credential reads every tenant's data, and a password is not the right lock on that. What was
 * wrong was where it sent somebody to do it. {@see EnsureAdminTwoFactor} redirected to the
 * product's own `settings/security`, which put a brand-new operator in the **customer** shell — and
 * an operator belongs to no team, so every destination in that shell's rail resolves
 * `currentTeam()->firstOrFail()` and answers 404. The panel asked for a second factor and handed
 * the operator a room where nothing worked.
 *
 * So the panel owns the screen. It is the same `ManageTwoFactor` component the settings page uses,
 * with the same Fortify endpoints behind it — nothing about enrolment is reimplemented here — in
 * the panel's own shell, where every link goes somewhere.
 *
 * **This is the one admin route without `EnsureAdminTwoFactor`**, which is the whole point of it,
 * and `AdminRouteContractTest` states that exception by name rather than letting the stack
 * assertion go soft. It is also the one without `PlatformContext`: enrolment reads nothing but the
 * operator's own account, and not removing tenant scoping is strictly safer than removing it for no
 * reason. Every other control still applies — signed in, an operator, from a permitted network,
 * inside the panel's own rate limit, carrying `AdminAbility::Access`.
 */
final class SecurityController extends Controller
{
    public function edit(TwoFactorAuthenticationRequest $request, ResourceRegistry $registry): Response
    {
        /** @var User $user */
        $user = $request->user();

        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            // Whether the panel is currently insisting, so the page can say *why* it is asking
            // rather than reading as a settings screen somebody wandered into.
            'required' => (bool) config('admin.require_two_factor', true),
            'twoFactorEnabled' => false,
            'requiresConfirmation' => false,
            // Resolved by name, because `ADMIN_PATH` moves the panel and a hard-coded `/admin` in
            // the page would be the one link that breaks on the one deployment that hides it.
            'panelUrl' => route('admin.dashboard'),
            // The rail, so the screen looks like part of the panel rather than a page that fell
            // out of it. It is read off `config/admin.php` and needs no tenant scope removed.
            'navigation' => $registry->navigation(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            // Fortify's own state check, exactly as the settings page runs it: it clears a
            // half-finished enrolment so the QR code on screen is the one the secret matches.
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $user->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(
                Features::twoFactorAuthentication(),
                'confirm',
            );
        }

        return Inertia::render('admin/Security', $props);
    }
}
