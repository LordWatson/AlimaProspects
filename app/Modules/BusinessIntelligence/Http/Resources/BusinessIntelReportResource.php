<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Http\Resources;

use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessIntelReport
 */
final class BusinessIntelReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'business_name' => $this->business_name,
            'website' => $this->website,
            // The AI findings are only present once the run is ready.
            'findings' => $this->findings,
            'model' => $this->model,
            'prompt_version' => $this->prompt_version,
            'error_message' => $this->error_message,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
