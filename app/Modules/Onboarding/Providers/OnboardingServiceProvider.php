<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Providers;

use App\Models\User;
use App\Modules\ModuleServiceProvider;
use App\Modules\Onboarding\Enums\OnboardingStep;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Onboarding\Policies\BusinessProfilePolicy;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

final class OnboardingServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        BusinessProfile::class => BusinessProfilePolicy::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->shareOnboardingStatus();
    }

    /**
     * Expose the current team's onboarding status to every Inertia page so the app shell and
     * dashboard can nudge the team to finish setup (and know when they already have).
     */
    private function shareOnboardingStatus(): void
    {
        Inertia::share('onboarding', function (): ?array {
            $user = Auth::user();

            if (! $user instanceof User || $user->currentTeamId() === null) {
                return null;
            }

            $progress = OnboardingProgress::query()
                ->where('team_id', $user->currentTeamId())
                ->first();

            return [
                'complete' => $progress?->isComplete() ?? false,
                'started' => $progress !== null,
                'currentStep' => $progress?->current_step->value ?? OnboardingStep::first()->value,
            ];
        });
    }
}
