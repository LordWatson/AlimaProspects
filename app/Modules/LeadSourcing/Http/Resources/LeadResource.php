<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Http\Resources;

use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lead
 */
final class LeadResource extends JsonResource
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
            'company_name' => $this->company_name,
            'company_number' => $this->company_number,
            'company_status' => $this->company_status,
            'company_type' => $this->company_type,
            'incorporated_on' => $this->incorporated_on?->toDateString(),
            'website' => $this->website,
            'locality' => $this->locality,
            'region' => $this->region,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'sic_codes' => $this->sic_codes ?? [],
            'source_url' => $this->source_url,
            'collected_at' => $this->collected_at?->toIso8601String(),
        ];
    }
}
