<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Integrations\Models\Integration;

/**
 * Saves the auto-sync rule and the deal switch (PL7).
 *
 * An Action rather than three lines in the controller, and the reason is the **audit entry** rather
 * than the write. Turning auto-sync on is the single most consequential setting in this module: it
 * authorises Alima to write into somebody's CRM with no person present, and every other act here —
 * connecting, disconnecting, saving an endpoint, rotating a secret — leaves a trail. A config change
 * with that effect and no record of who made it is the one an Owner would most want to be able to
 * ask about.
 *
 * `settings` is merged rather than replaced, so a key a connector added (a pipeline id, an owner id,
 * a field map) is not wiped by a member saving the form that does not know about it.
 */
final class UpdateIntegrationSettingsAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(
        Integration $integration,
        User $actor,
        bool $autoSync,
        ?int $minConfidence,
        ?int $minFit,
        bool $createDeals,
    ): Integration {
        $integration->forceFill([
            'settings' => [
                ...($integration->settings ?? []),
                'auto_sync' => [
                    'enabled' => $autoSync,
                    'min_confidence' => $minConfidence,
                    'min_fit' => $minFit,
                ],
                'create_deals' => $createDeals,
            ],
        ])->save();

        $this->audit->log('integration.settings_updated', $integration, [
            'provider' => $integration->provider->value,
            // The thresholds, because the question somebody asks afterwards is "when did this start
            // pushing on its own, and at what bar".
            'auto_sync' => $autoSync,
            'min_confidence' => $minConfidence,
            'min_fit' => $minFit,
            'create_deals' => $createDeals,
        ], $actor);

        return $integration;
    }
}
