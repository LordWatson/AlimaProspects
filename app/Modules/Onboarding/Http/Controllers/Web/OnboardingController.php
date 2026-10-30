<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Enums\Currency;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Onboarding\Actions\CompleteOnboardingAction;
use App\Modules\Onboarding\Actions\GetOnboardingStateAction;
use App\Modules\Onboarding\Actions\SaveOnboardingStepAction;
use App\Modules\Onboarding\Enums\CompanySize;
use App\Modules\Onboarding\Enums\OnboardingStep;
use App\Modules\Onboarding\Enums\ServedRegion;
use App\Modules\Onboarding\Http\Requests\SaveOnboardingStepRequest;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Onboarding\Support\OnboardingState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The onboarding wizard (Inertia). Thin: authorise via the Business Profile policy, delegate to the
 * Save/Complete Actions, and render the resumable wizard with the current profile, progress, step
 * metadata and option lists.
 */
final class OnboardingController extends Controller
{
    public function show(Request $request, GetOnboardingStateAction $getState): Response
    {
        $team = $this->team($request);
        $state = $getState->handle($team);
        $profile = $this->authProfile($state, $team);

        $this->authorize('view', $profile);

        $progress = $state->progress;

        return Inertia::render('onboarding/Wizard', [
            'steps' => $this->steps(),
            'progress' => $progress instanceof OnboardingProgress
                ? [
                    'currentStep' => $progress->current_step->value,
                    'completedSteps' => $progress->completed_steps ?? [],
                    'isComplete' => $progress->isComplete(),
                ]
                : [
                    'currentStep' => OnboardingStep::first()->value,
                    'completedSteps' => [],
                    'isComplete' => false,
                ],
            'profile' => $this->profilePayload($profile, $team),
            'options' => $this->options(),
            'can' => [
                'update' => $request->user()?->can('update', $profile) ?? false,
            ],
        ]);
    }

    public function saveStep(SaveOnboardingStepRequest $request, GetOnboardingStateAction $getState, SaveOnboardingStepAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);

        $this->authorize('update', $this->authProfile($getState->handle($team), $team));

        $action->handle($team, $request->step(), $request->validated(), $user);

        return back();
    }

    public function complete(Request $request, GetOnboardingStateAction $getState, CompleteOnboardingAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);

        $this->authorize('update', $this->authProfile($getState->handle($team), $team));

        $action->handle($team, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __("You're all set. Alima is getting to work on your background check.")]);

        return to_route('dashboard');
    }

    private function team(Request $request): Team
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        return $team;
    }

    /**
     * The profile to authorise against — the stored one, or a transient team-scoped stand-in when
     * the team hasn't started onboarding (so the policy still resolves the team).
     */
    private function authProfile(OnboardingState $state, Team $team): BusinessProfile
    {
        return $state->profile ?? (new BusinessProfile)->forceFill(['team_id' => $team->getKey()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(BusinessProfile $profile, Team $team): array
    {
        $factor = $profile->pricing_currency?->minorUnitFactor() ?? 100;
        // Pull nullsafe reads into locals so the fallbacks below stay simple coalesces.
        $marketValue = $profile->primary_market?->value;
        $currencyValue = $profile->pricing_currency?->value;
        $defaultCurrency = Market::tryFrom($team->market)?->defaultCurrency()->value;

        return [
            'company_name' => $profile->company_name,
            'website' => $profile->website,
            'industry' => $profile->industry,
            'company_size' => $profile->company_size?->value,
            'locations' => $profile->locations ?? [],
            'regions_served' => $profile->regions_served ?? [],
            // Pre-fill the primary market from the team's current market for a fresh profile.
            'primary_market' => $marketValue ?? $team->market,
            'products_services' => $profile->products_services ?? [],
            'value_proposition' => $profile->value_proposition,
            // Minor units → major units for the form inputs.
            'pricing_min' => $profile->pricing_min !== null ? $profile->pricing_min / $factor : null,
            'pricing_max' => $profile->pricing_max !== null ? $profile->pricing_max / $factor : null,
            'pricing_currency' => $currencyValue ?? $defaultCurrency,
            'customer_examples' => $profile->customer_examples ?? [],
            'version' => $profile->version,
            'isComplete' => $profile->isComplete(),
        ];
    }

    /**
     * Step metadata for the progress rail.
     *
     * @return list<array<string, mixed>>
     */
    private function steps(): array
    {
        return array_map(static fn (OnboardingStep $step): array => [
            'key' => $step->value,
            'label' => $step->label(),
            'position' => $step->position(),
            'isData' => $step->isDataStep(),
        ], OnboardingStep::cases());
    }

    /**
     * Option lists for the wizard selects.
     *
     * @return array<string, list<array<string, string>>>
     */
    private function options(): array
    {
        return [
            'companySizes' => array_map(static fn (CompanySize $s): array => ['value' => $s->value, 'label' => $s->label()], CompanySize::cases()),
            'regions' => array_map(static fn (ServedRegion $r): array => ['value' => $r->value, 'label' => $r->label()], ServedRegion::cases()),
            'markets' => array_map(static fn (Market $m): array => ['value' => $m->value, 'label' => $m->label()], Market::cases()),
            'currencies' => array_map(static fn (Currency $c): array => ['value' => $c->value, 'label' => $c->label()], Currency::cases()),
        ];
    }
}
