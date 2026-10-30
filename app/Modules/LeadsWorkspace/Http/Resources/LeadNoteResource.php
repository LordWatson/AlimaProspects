<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Resources;

use App\Modules\LeadsWorkspace\Models\LeadNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A note on a lead. `can_delete` is resolved here rather than on the client so the screen and the
 * policy can't disagree about which notes show a delete button.
 *
 * @mixin LeadNote
 */
final class LeadNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'author' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'can_delete' => $request->user()?->can('delete', $this->resource) ?? false,
        ];
    }
}
