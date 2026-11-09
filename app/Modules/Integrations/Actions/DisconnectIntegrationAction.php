<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Models\Integration;

/**
 * Drops the credentials and stops the sync (PL7).
 *
 * **The row and its links survive.** Deleting them would mean a team who disconnects on Friday and
 * reconnects on Monday has every organisation created a second time in their CRM — which is the
 * expensive, visible, unfixable kind of mistake, since the duplicates are in somebody else's system.
 * The links are only wrong if the *account* changed, and {@see ConnectIntegrationAction} is what
 * checks that.
 *
 * The tokens **are** cleared, immediately: a disconnected integration holding a live access token
 * is a credential we have been asked to stop having.
 */
final class DisconnectIntegrationAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Integration $integration, User $actor): void
    {
        $integration->forceFill([
            'status' => IntegrationStatus::Disconnected,
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'last_error' => null,
        ])->save();

        $this->audit->log('integration.disconnected', $integration, [
            'provider' => $integration->provider->value,
        ], $actor);
    }
}
