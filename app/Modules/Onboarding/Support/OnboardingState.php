<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Support;

use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Onboarding\Models\OnboardingProgress;

/**
 * The current team's onboarding state — the (possibly absent) Business Profile and wizard progress
 * — read once and handed to the controllers. Keeps tenant reads in an Action, not controllers.
 */
final readonly class OnboardingState
{
    public function __construct(
        public ?BusinessProfile $profile,
        public ?OnboardingProgress $progress,
    ) {}
}
