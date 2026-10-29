<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Resources;

use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One column of the board. `name` is the team's own word and goes out verbatim; `outcome_label` is
 * a translation key, because that one *is* the product's vocabulary.
 *
 * @mixin PipelineStage
 */
final class PipelineStageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'position' => $this->position,
            'is_default' => $this->is_default,
            'outcome' => $this->outcome?->value,
            'outcome_label' => $this->outcome?->label(),
            'leads_count' => $this->whenCounted('leads'),
        ];
    }
}
