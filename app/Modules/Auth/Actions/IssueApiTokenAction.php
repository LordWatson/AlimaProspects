<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Exceptions\CapabilityException;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Issue a Sanctum personal access token for the user and return the one-time plaintext value.
 * The plaintext is shown to the user once and never stored in readable form.
 *
 * **The `api_access` capability is checked here, not only on the route** (Phase 19b, ADR 0015).
 * The settings screen's route carries `capability:api_access` as the courtesy half, but the mobile
 * two-factor exchange issues a token from an endpoint with no session and no team-scoped middleware
 * behind it — so the route check alone left one way to get a credential for a plan that does not
 * include the API. This is the guard half, in the Action, for the same reason every other
 * capability is enforced in an Action rather than in middleware.
 */
final class IssueApiTokenAction
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CapabilityGate $capabilities,
    ) {}

    /**
     * @param  list<string>  $abilities
     *
     * @throws CapabilityException
     */
    public function handle(User $user, string $name, array $abilities = ['*']): string
    {
        $team = $user->currentTeam()->first();

        // No team is not a plan question: a user with no team has nothing to scope a token to, and
        // the caller's own `auth` is what should be refusing that.
        if ($team instanceof Team && ! $this->capabilities->allows($team, Capability::ApiAccess)) {
            throw CapabilityException::unavailable(Capability::ApiAccess);
        }

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
