<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Http\Resources;

use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessProfile
 */
final class BusinessProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'website' => $this->website,
            'industry' => $this->industry,
            'company_size' => $this->company_size?->value,
            'company_size_label' => $this->company_size?->label(),
            'locations' => $this->locations ?? [],
            'regions_served' => $this->regions_served ?? [],
            'primary_market' => $this->primary_market?->value,
            'products_services' => $this->products_services ?? [],
            'value_proposition' => $this->value_proposition,
            // Amounts are integer minor units; `currency` is the ISO 4217 code.
            'pricing' => [
                'min' => $this->pricing_min,
                'max' => $this->pricing_max,
                'currency' => $this->pricing_currency?->code(),
            ],
            'customer_examples' => $this->customer_examples ?? [],
            'version' => $this->version,
            'is_complete' => $this->isComplete(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
