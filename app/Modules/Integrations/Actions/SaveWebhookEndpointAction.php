<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\Security\SafeUrl;
use App\Modules\Integrations\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits one outbound endpoint (PL7).
 *
 * **The URL is stored normalised**, through the same {@see SafeUrl} the sender uses. The form has
 * already refused an address this application may not reach — a member typing one deserves the
 * answer beside the field, not a failed delivery an hour later — and normalising here as well means
 * the string the sender reads is the string the guard approved, rather than something a second
 * code path trimmed differently.
 *
 * **Re-enabling clears the failure count and the auto-disable stamp.** A member switching an
 * endpoint back on has fixed something, and leaving nineteen failures on the row would have us
 * disable it again on the first bad response. The two fields are cleared together because they are
 * the same fact.
 *
 * The secret is minted on creation and **returned once**, in the clear. Nothing reads it back.
 */
final class SaveWebhookEndpointAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $events
     * @return array{endpoint: WebhookEndpoint, secret: string|null}
     */
    public function handle(
        Team $team,
        User $actor,
        string $url,
        array $events,
        bool $isActive = true,
        ?WebhookEndpoint $existing = null,
    ): array {
        $normalised = SafeUrl::normalise($url) ?? $url;

        $result = DB::transaction(function () use ($team, $normalised, $events, $isActive, $existing): array {
            if ($existing !== null) {
                $existing->fill([
                    'url' => $normalised,
                    'events' => $events,
                    'is_active' => $isActive,
                ]);

                if ($isActive) {
                    $existing->forceFill(['failure_count' => 0, 'disabled_at' => null]);
                }

                $existing->save();

                return ['endpoint' => $existing, 'secret' => null];
            }

            $minted = WebhookEndpoint::mint();

            $endpoint = new WebhookEndpoint([
                'team_id' => $team->getKey(),
                'url' => $normalised,
                'events' => $events,
                'is_active' => $isActive,
            ]);

            // Not fillable: a request that could assert a secret could assert a known one.
            $endpoint->forceFill($minted)->save();

            return ['endpoint' => $endpoint, 'secret' => $minted['secret']];
        });

        $this->audit->log(
            $existing === null ? 'webhook_endpoint.created' : 'webhook_endpoint.updated',
            $result['endpoint'],
            // The events and the host, never the whole URL: a catch-hook URL carries an opaque
            // token and is a credential in its own right.
            ['events' => $events, 'host' => (string) parse_url($normalised, PHP_URL_HOST)],
            $actor,
        );

        return $result;
    }
}
