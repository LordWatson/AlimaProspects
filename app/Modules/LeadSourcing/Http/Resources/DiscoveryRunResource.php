<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Http\Resources;

use App\Modules\LeadSourcing\Models\DiscoveryRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DiscoveryRun
 */
final class DiscoveryRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'icp' => $this->whenLoaded('icp', fn (): ?array => $this->icp === null ? null : [
                'id' => $this->icp->id,
                'name' => $this->icp->name,
            ]),
            'params' => $this->params,
            'requested_limit' => $this->requested_limit,
            'leads_found' => $this->leads_found,
            'leads_created' => $this->leads_created,
            'cost_micros' => $this->cost_micros,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // The leads this run produced, when the relation is loaded (detail screen only).
            'leads' => LeadResource::collection($this->whenLoaded('leads')),
        ];
    }
}
