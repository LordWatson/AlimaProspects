<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Issue a Sanctum personal access token for the user and return the one-time plaintext value.
 * The plaintext is shown to the user once and never stored in readable form.
 */
final class IssueApiTokenAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $abilities
     */
    public function handle(User $user, string $name, array $abilities = ['*']): string
    {
        return DB::transaction(function () use ($user, $name, $abilities): string {
            $token = $user->createToken($name, $abilities === [] ? ['*'] : $abilities);

            $this->audit->log('api_token.created', $user, [
                'token_id' => $token->accessToken->getKey(),
                'name' => $name,
                'abilities' => $abilities === [] ? ['*'] : $abilities,
            ], $user);

            return $token->plainTextToken;
        });
    }
}
