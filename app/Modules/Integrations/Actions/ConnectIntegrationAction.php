<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Integrations\Enums\CrmProvider;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Models\IntegrationLink;
use App\Modules\Integrations\Services\CrmConnectorRegistry;
use App\Modules\Integrations\Services\OAuthStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns an authorisation code into a usable connection (PL7).
 *
 * Called after {@see OAuthStateService} has already approved the
 * callback. This Action does the one thing that is about **data** rather than about authentication,
 * and it is the reason it is an Action at all:
 *
 * **If the CRM account has changed, the old links are deleted.** `integration_links` is a memory of
 * "their id for our row", and reconnecting to a *different* Pipedrive account with those links
 * still in place would have Alima updating organisation 4821 inside a company that has never heard
 * of us — writing one customer's lead data into another's CRM. So the account id that comes back
 * with the tokens is compared with the stored one, and a mismatch clears the links rather than
 * trusting them. A reconnect to the **same** account keeps them, which is the common case and the
 * reason the row is not simply deleted on disconnect.
 *
 * A provider that tells us nothing about the account is treated as a change, because "we do not
 * know" and "it is the same" must not be the same branch here.
 */
final class ConnectIntegrationAction
{
    public function __construct(
        private readonly CrmConnectorRegistry $connectors,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Team $team, User $actor, CrmProvider $provider, string $code): Integration
    {
        $credentials = $this->connectors->for($provider)->handleCallback($code);

        $integration = DB::transaction(function () use ($team, $provider, $credentials): Integration {
            $integration = Integration::query()
                ->withoutGlobalScopes()
                ->where('team_id', $team->getKey())
                ->where('provider', $provider->value)
                ->first()
                ?? new Integration(['team_id' => $team->getKey(), 'provider' => $provider->value]);

            $accountChanged = $integration->exists
                && ($credentials->accountId === null
                    || $integration->external_account_id !== $credentials->accountId);

            $integration->forceFill([
                'status' => IntegrationStatus::Connected,
                'access_token' => $credentials->accessToken,
                'refresh_token' => $credentials->refreshToken,
                'expires_at' => $credentials->expiresAt,
                'external_account_id' => $credentials->accountId,
                'external_account_name' => $credentials->accountName,
                'last_error' => null,
            ])->save();

            if ($accountChanged) {
                // See the class note: a link to a record in somebody else's CRM account is worse
                // than no link.
                IntegrationLink::query()
                    ->withoutGlobalScopes()
                    ->where('integration_id', $integration->getKey())
                    ->delete();

                Log::info('CRM account changed on reconnect; links cleared.', [
                    'integration_id' => $integration->getKey(),
                    'team_id' => $team->getKey(),
                    'provider' => $provider->value,
                ]);
            }

            return $integration;
        });

        $this->audit->log('integration.connected', $integration, [
            'provider' => $provider->value,
            // The account id, not the tokens. It is the one piece of this a support conversation
            // needs and the one piece that is not a credential.
            'external_account_id' => $credentials->accountId,
        ], $actor);

        return $integration;
    }
}
