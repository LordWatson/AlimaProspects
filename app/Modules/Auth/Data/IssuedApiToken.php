<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Models\User;

/**
 * A freshly minted personal access token and who it belongs to (Phase 18).
 *
 * Not a Spatie `Data`: the plaintext token is shown once and must never find its way into a
 * serialised payload by default. This is a value object the controller unpacks by hand, so the one
 * place it is written to a response is visible.
 */
final readonly class IssuedApiToken
{
    public function __construct(
        public User $user,
        /** The one-time plaintext. Never stored in readable form. */
        public string $plainTextToken,
    ) {}
}
