<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Resources;

use App\Modules\LeadsWorkspace\Models\SavedView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved leads view. `filters` is re-read through `LeadFilters` on the way out, so a view stored
 * before a filter was renamed still arrives as something the current list understands.
 *
 * @mixin SavedView
 */
final class SavedViewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $userId = $request->user()?->getKey();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'filters' => $this->toFilters()->toParams(),
            'is_shared' => $this->is_shared,
            // Whether the viewer may delete it — the same rule as SavedViewPolicy::delete, minus
            // the Owner/Admin tidy-up path, which the screen doesn't advertise.
            'is_mine' => $userId !== null && (int) $this->user_id === (int) $userId,
            'owner' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
        ];
    }
}
