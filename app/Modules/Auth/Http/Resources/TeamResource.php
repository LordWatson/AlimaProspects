<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Team
 */
final class TeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'market' => $this->market,
            'role' => $this->whenPivotLoaded('team_user', function (): ?string {
                $pivot = $this->getAttribute('pivot');
                $role = $pivot instanceof Pivot ? $pivot->getAttribute('role') : null;

                return is_string($role) ? $role : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
