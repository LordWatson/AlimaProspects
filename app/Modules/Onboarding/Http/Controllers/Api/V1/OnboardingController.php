<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Onboarding\Actions\GetOnboardingStateAction;
use App\Modules\Onboarding\Http\Resources\OnboardingStateResource;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Http\Request;

/**
 * Read-only `/api/v1` view of the current team's onboarding — the Business Profile plus wizard
 * progress. Consumed by the mobile app (read-only summary).
 */
final class OnboardingController extends Controller
{
    public function show(Request $request, GetOnboardingStateAction $getState): OnboardingStateResource
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        $state = $getState->handle($team);

        $this->authorize('view', $state->profile ?? (new BusinessProfile)->forceFill(['team_id' => $team->getKey()]));

        return OnboardingStateResource::make($state);
    }
}
