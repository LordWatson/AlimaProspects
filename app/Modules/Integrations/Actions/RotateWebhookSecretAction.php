<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Integrations\Models\WebhookEndpoint;

/**
 * Replaces an endpoint's signing secret, and shows the new one once (PL7).
 *
 * **Replaces rather than adds.** Two valid secrets at once is a grace period nobody asked for and a
 * second thing to find when one leaks, which is the same decision the inbound secret made (S13b).
 * The cost is explicit and belongs on the screen: the next delivery is signed with the new secret,
 * so a receiver that has not been updated will reject it — and that is exactly what a customer
 * rotating a leaked secret wants to happen.
 *
 * An Action rather than two lines in the controller because it is audited, and because minting is
 * the one thing in this module that must not have a second implementation.
 */
final class RotateWebhookSecretAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(WebhookEndpoint $endpoint, User $actor): string
    {
        $minted = WebhookEndpoint::mint();

        $endpoint->forceFill($minted)->save();

        $this->audit->log('webhook_endpoint.secret_rotated', $endpoint, [], $actor);

        return $minted['secret'];
    }
}
