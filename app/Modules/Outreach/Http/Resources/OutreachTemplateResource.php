<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Resources;

use App\Modules\Outreach\Models\OutreachTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A team's message brief. Labels are translation keys (EN/AR, ADR 0007).
 *
 * @mixin OutreachTemplate
 */
final class OutreachTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel' => $this->channel->value,
            'channel_label' => $this->channel->label(),
            'tone' => $this->tone->value,
            'tone_label' => $this->tone->label(),
            'locale' => $this->locale->value,
            'subject' => $this->subject,
            'body' => $this->body,
            'is_default' => $this->is_default,
            'created_at' => $this->created_at?->toIso8601String(),
            'can_update' => $request->user()?->can('update', $this->resource) ?? false,
            'can_delete' => $request->user()?->can('delete', $this->resource) ?? false,
        ];
    }
}
