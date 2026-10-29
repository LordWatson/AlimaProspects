<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Http\Resources;

use App\Modules\Onboarding\Models\OnboardingProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OnboardingProgress
 */
final class OnboardingProgressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'current_step' => $this->current_step->value,
            'completed_steps' => $this->completed_steps ?? [],
            'is_complete' => $this->isComplete(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
