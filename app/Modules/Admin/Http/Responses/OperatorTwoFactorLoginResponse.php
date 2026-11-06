<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same landing rule, for the login that passed a second factor.
 *
 * Bound alongside {@see OperatorLoginResponse} rather than left to Fortify's default, because an
 * operator **is required** to have two-factor authentication (`EnsureAdminTwoFactor`) — so once
 * they have enrolled, this is the only response their logins ever reach. Fixing one and not the
 * other would have fixed the 404 exactly until the operator did the thing the panel insists on.
 */
final class OperatorTwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $panel = OperatorLoginResponse::panelFor($request);

        return $panel === null
            ? redirect()->intended(Fortify::redirects('login'))
            : redirect()->to($panel);
    }
}
