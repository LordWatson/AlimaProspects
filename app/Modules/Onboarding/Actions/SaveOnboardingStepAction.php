<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Actions;

use App\Models\User;
use App\Modules\Core\Enums\Currency;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Onboarding\Enums\OnboardingStep;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Onboarding\Models\OnboardingProgress;
use Illuminate\Support\Facades\DB;

/**
 * Persist one step of the onboarding wizard (resumable). Writes only the fields that belong to the
 * given step onto the team's Business Profile, records the step as completed and advances the
 * progress pointer. The caller (a FormRequest) is the validation boundary — `$attributes` are the
 * already-validated values for exactly this step.
 */
final class SaveOnboardingStepAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated values for this step
     */
    public function handle(Team $team, OnboardingStep $step, array $attributes, ?User $actor = null): BusinessProfile
    {
        return $this->teamContext->run($team, fn (): BusinessProfile => DB::transaction(function () use ($team, $step, $attributes, $actor): BusinessProfile {
            $profile = BusinessProfile::query()->firstOrNew(['team_id' => $team->getKey()]);
            $profile->fill($this->normalise($step, $attributes));
            $profile->save();

            $progress = OnboardingProgress::query()->firstOrNew(['team_id' => $team->getKey()]);
            $progress->markStepCompleted($step);
            $progress->save();

            $this->audit->log('onboarding.step_saved', $profile, ['step' => $step->value], $actor);

            return $profile;
        }));
    }

    /**
     * Shape validated input into storage form (e.g. money → integer minor units).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalise(OnboardingStep $step, array $attributes): array
    {
        if ($step === OnboardingStep::Pricing) {
            $currency = isset($attributes['pricing_currency']) && is_string($attributes['pricing_currency'])
                ? Currency::tryFrom($attributes['pricing_currency'])
                : null;
            $factor = $currency?->minorUnitFactor() ?? 100;

            foreach (['pricing_min', 'pricing_max'] as $field) {
                if (isset($attributes[$field]) && is_numeric($attributes[$field])) {
                    $attributes[$field] = (int) round(((float) $attributes[$field]) * $factor);
                }
            }
        }

        return $attributes;
    }
}
