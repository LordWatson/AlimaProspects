<?php

declare(strict_types=1);

namespace App\Modules\ICP\Http\Resources;

use App\Modules\ICP\Models\IcpCriterion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IcpCriterion
 */
final class IcpCriterionResource extends JsonResource
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
            'value' => $this->value,
            'weight' => $this->weight,
            'is_exclusion' => $this->isExclusion(),
            'position' => $this->position,
        ];
    }
}
