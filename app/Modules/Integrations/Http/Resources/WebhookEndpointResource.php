<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Resources;

use App\Modules\Integrations\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One endpoint, as a screen sees it (PL7).
 *
 * **The secret is not here and has no conditional that could put it here.** It is returned once, by
 * the call that minted it, and nothing reads it back — which is the whole protection, because a
 * value a resource could be asked for is a value that ends up in a screenshot. `hint` is the last
 * eight characters, which is enough to tell two endpoints apart and useless to anybody else.
 *
 * `failure_count` and `disabled_at` are both sent, because they answer different questions: one is
 * "is this endpoint healthy", the other is "did we switch it off" — and a member whose endpoint
 * stopped needs to know which.
 *
 * @mixin WebhookEndpoint
 */
final class WebhookEndpointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'hint' => $this->hint,
            'events' => $this->events,
            'is_active' => $this->is_active,
            'failure_count' => $this->failure_count,
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'last_delivered_at' => $this->last_delivered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
