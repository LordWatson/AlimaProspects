<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Revoke one of the user's personal access tokens. Scoped to the owner so a user can only
 * delete their own tokens. Returns true when a token was deleted.
 */
final class RevokeApiTokenAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, int|string $tokenId): bool
    {
        return DB::transaction(function () use ($user, $tokenId): bool {
            $token = $user->tokens()->whereKey($tokenId)->first();

            if ($token === null) {
                return false;
            }

            $this->audit->log('api_token.revoked', $user, [
                'token_id' => $token->getKey(),
                'name' => $token->name,
            ], $user);

            $token->delete();

            return true;
        });
    }
}
