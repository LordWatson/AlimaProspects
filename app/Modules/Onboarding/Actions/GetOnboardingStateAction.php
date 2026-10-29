<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Onboarding\Support\OnboardingState;

/**
 * Read a team's onboarding state (Business Profile + progress) in one place, scoped to the team.
 * Shared by the web and API controllers so neither queries directly.
 */
final class GetOnboardingStateAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    public function handle(Team $team): OnboardingState
    {
        return $this->teamContext->run($team, fn (): OnboardingState => new OnboardingState(
            profile: BusinessProfile::query()->where('team_id', $team->getKey())->first(),
            progress: OnboardingProgress::query()->where('team_id', $team->getKey())->first(),
        ));
    }
}
