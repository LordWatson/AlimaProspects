<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Resources;

use App\Modules\LeadsWorkspace\Models\LeadActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One timeline entry. `type` + `properties` are what the client renders the sentence from — the
 * server sends values, not prose, because the workspace is EN/AR (ADR 0007). `type_label` is a
 * translation key, not a finished string.
 *
 * `subject_type` is deliberately *not* exposed: it is an internal class name, and the client has no
 * use for it beyond the type it already has.
 *
 * @mixin LeadActivity
 */
final class LeadActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'is_automatic' => $this->type->isAutomatic(),
            'properties' => $this->properties ?? [],
            // Null means Alima did it, which is what the timeline shows rather than a blank name.
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
