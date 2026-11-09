<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\Actions\SyncLeadToCrmAction;
use App\Modules\Integrations\Contracts\CrmConnector;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a stored OAuth token usable, and gives up loudly when it cannot (PL7).
 *
 * Every connector call that needs a credential goes through {@see self::call()}, which does three
 * things in an order that matters:
 *
 * 1. **Refreshes a token that is about to expire**, before the call rather than after it fails.
 *    A token with forty seconds left is a token that expires mid-request
 *    ({@see Integration::REFRESH_SKEW_SECONDS}).
 * 2. **Retries exactly once on a refusal.** A provider that revoked early, or a clock that drifted,
 *    is recoverable; and once is the right number, because the second refusal is the provider
 *    telling us the refresh token is dead too and a loop there is a loop against somebody else's
 *    rate limit.
 * 3. **Marks `needs_reauth` and notifies the team's admins** when the refresh itself fails. This is
 *    the one failure in the module that a member *must* be told about: a dead connection is silent,
 *    and a team would otherwise find out weeks later that nothing has reached their CRM since a
 *    password change. `IntegrationStatus` keeps this apart from an ordinary error for exactly that
 *    reason — an email asking somebody to reconnect a connection that is fine is how a real alert
 *    gets ignored.
 *
 * Not middleware, although the plan called it that: it wraps a **call**, not a request, and the
 * callers are a queued job and a settings controller. A Laravel middleware would have nothing to
 * sit on.
 *
 * @see SyncLeadToCrmAction the only caller that pushes
 */
final class TokenRefresher
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    /**
     * Run `$call` with a usable credential.
     *
     * @template TReturn
     *
     * @param  callable(Integration): TReturn  $call
     * @return TReturn
     *
     * @throws IntegrationException when only a person can fix it
     */
    public function call(Integration $integration, CrmConnector $connector, callable $call): mixed
    {
        if ($integration->tokenIsStale()) {
            $this->refresh($integration, $connector);
        }

        try {
            return $call($integration);
        } catch (IntegrationException $e) {
            if ($e->errorCode() !== 'integration.unauthorized') {
                throw $e;
            }

            // One retry, with a fresh token. A second refusal after a successful refresh is the
            // provider saying something other than "your token was old", and retrying it again
            // would be a loop against their rate limit.
            $this->refresh($integration, $connector);

            return $call($integration);
        }
    }

    /**
     * Swap the refresh token for a new access token, or declare the connection dead.
     */
    private function refresh(Integration $integration, CrmConnector $connector): void
    {
        try {
            $credentials = $connector->refresh($integration);
        } catch (Throwable $e) {
            $this->markNeedsReauth($integration, $e);

            throw IntegrationException::needsReauth($integration->provider);
        }

        $integration->forceFill([
            'access_token' => $credentials->accessToken,
            // Kept when the provider did not send a new one: many rotate the access token only, and
            // overwriting the refresh token with null would end the connection on the next refresh.
            'refresh_token' => $credentials->refreshToken ?? $integration->refresh_token,
            'expires_at' => $credentials->expiresAt,
            'status' => IntegrationStatus::Connected,
            'last_error' => null,
        ])->save();
    }

    private function markNeedsReauth(Integration $integration, Throwable $e): void
    {
        $integration->forceFill([
            'status' => IntegrationStatus::NeedsReauth,
            // The exception class, not its message: a provider's 401 body has been known to echo
            // the token back, and this column is rendered on a settings screen.
            'last_error' => $e::class,
            'access_token' => null,
        ])->save();

        Log::warning('CRM token refresh failed.', [
            'integration_id' => $integration->getKey(),
            'team_id' => $integration->team_id,
            'provider' => $integration->provider->value,
            'exception' => $e::class,
        ]);

        $this->notifications->toAdmins($integration->team, NotificationType::IntegrationNeedsReauth, [
            'provider' => $integration->provider->value,
            'provider_label' => $integration->provider->label(),
        ]);
    }
}
