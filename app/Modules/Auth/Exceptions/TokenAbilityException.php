<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Enums\ApiErrorCode;
use App\Modules\Core\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The acting token is authentic but not scoped for this endpoint (Phase 17).
 *
 * A 403 rather than a 401: re-authenticating would not help, and telling a client to go and sign
 * in again when the real answer is "mint a token with `leads:write`" sends it round a loop. The
 * missing abilities travel in `details` so the client can say which scope to add instead of
 * guessing.
 */
final class TokenAbilityException extends DomainException
{
    protected int $status = Response::HTTP_FORBIDDEN;

    protected string $errorCode = ApiErrorCode::InsufficientTokenAbility->value;

    /**
     * @param  list<string>  $required  Any one of these would have been enough.
     */
    public static function missing(array $required): self
    {
        $e = new self(ApiErrorCode::InsufficientTokenAbility->message());
        $e->details = ['required_abilities' => $required];

        return $e;
    }
}
