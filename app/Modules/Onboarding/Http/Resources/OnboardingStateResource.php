<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Http\Resources;

use App\Modules\Onboarding\Support\OnboardingState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property OnboardingState $resource
 */
final class OnboardingStateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'profile' => $this->resource->profile !== null
                ? BusinessProfileResource::make($this->resource->profile)
                : null,
            'progress' => $this->resource->progress !== null
                ? OnboardingProgressResource::make($this->resource->progress)
                : null,
        ];
    }
}
