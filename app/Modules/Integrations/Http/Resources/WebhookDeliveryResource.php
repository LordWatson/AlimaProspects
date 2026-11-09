<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Resources;

use App\Modules\Integrations\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One delivery attempt, for the log (PL7).
 *
 * **The payload is not here.** The log answers "did it arrive, and what did they say" — the body is
 * the team's own lead data, it is already on the screen the lead came from, and putting a copy of
 * every lead into the props of a settings page would make that page the largest response in the
 * product. A member who needs the body has their own receiver's logs.
 *
 * The response snippet **is** here, truncated at write. It is the one thing only we can show them,
 * and the reason a 400 is debuggable at all.
 *
 * @mixin WebhookDelivery
 */
final class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Which endpoint this went to. A team with three endpoints could not tell their log
            // rows apart without it, which made the log unusable for the one team that most needs
            // it (found by review).
            'endpoint_id' => $this->webhook_endpoint_id,
            'event' => $this->event->value,
            'event_label' => $this->event->label(),
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'attempt' => $this->attempt,
            'status_code' => $this->status_code,
            'response_snippet' => $this->response_snippet,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
