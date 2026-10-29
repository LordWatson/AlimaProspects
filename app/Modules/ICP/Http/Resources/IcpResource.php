<?php

declare(strict_types=1);

namespace App\Modules\ICP\Http\Resources;

use App\Modules\ICP\Models\Icp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Icp
 */
final class IcpResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'criteria' => IcpCriterionResource::collection($this->whenLoaded('criteria')),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
