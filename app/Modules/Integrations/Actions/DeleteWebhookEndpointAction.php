<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Integrations\Models\WebhookEndpoint;

/**
 * Removes an endpoint and its delivery log (PL7).
 *
 * An Action for the audit entry, for the reason {@see UpdateIntegrationSettingsAction} is one: this
 * is where a team's events **stopped** going somewhere, and "when did we stop receiving these" is a
 * support question with no other answer once the row is gone.
 *
 * The deliveries go with it (`cascadeOnDelete`): a log of posts to an endpoint nobody can see is a
 * log of nothing, and it is the one table here that holds a copy of a lead's payload per row.
 *
 * The audit entry keeps the **host** and the event list rather than the URL, as the save does — a
 * catch-hook URL carries an opaque token and is a credential in its own right.
 */
final class DeleteWebhookEndpointAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(WebhookEndpoint $endpoint, User $actor): void
    {
        $this->audit->log('webhook_endpoint.deleted', $endpoint, [
            'events' => $endpoint->events,
            'host' => (string) parse_url($endpoint->url, PHP_URL_HOST),
        ], $actor);

        $endpoint->delete();
    }
}
