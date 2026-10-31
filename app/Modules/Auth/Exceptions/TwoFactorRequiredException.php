<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The password was right and two-factor authentication is on, so the credentials alone are not
 * enough to mint a token (Phase 18).
 *
 * A `423` rather than a `422`: nothing the caller sent was invalid, and nothing it could re-send
 * unchanged will work. The request is held, pending a second factor. The code is the contract —
 * a client sees `auth.two_factor_required` and opens its challenge screen rather than putting a
 * "those details were wrong" message under the password field, which is the sentence a 422 would
 * have it write.
 *
 * `details.methods` names what the account can answer with, because they are not interchangeable:
 * a `code` is six digits from the authenticator app, a `recovery_code` is one of the one-time
 * strings written down when it was set up, and a screen that offers the wrong one asks for
 * something the person does not have.
 */
final class TwoFactorRequiredException extends DomainException
{
    protected int $status = Response::HTTP_LOCKED;

    protected string $errorCode = 'auth.two_factor_required';

    /**
     * @param  list<string>  $methods  The input names `POST /auth/token` will accept next.
     */
    public static function withMethods(array $methods): self
    {
        $e = new self(__('Enter the code from your authenticator app to finish signing in.'));
        $e->details = ['methods' => $methods];

        return $e;
    }
}
