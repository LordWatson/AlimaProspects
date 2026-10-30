<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One notification as the bell reads it.
 *
 * `title` and `body` are **translation keys**, and `params` the values to interpolate into them —
 * never finished prose. The row may have been written days ago and the member may since have
 * switched the app to Arabic (ADR 0007), so the sentence is composed at render time by `t()`.
 *
 * @mixin Notification
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->notificationType()?->value,
            'title' => (string) ($this->data['title'] ?? ''),
            'body' => (string) ($this->data['body'] ?? ''),
            'params' => $this->params(),
            'url' => $this->url(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
