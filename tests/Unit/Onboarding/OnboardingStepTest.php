<?php

declare(strict_types=1);

use App\Modules\Onboarding\Enums\OnboardingStep;

it('orders the data steps before review', function (): void {
    expect(OnboardingStep::dataValues())
        ->toBe(['company', 'industry', 'locations', 'offering', 'pricing'])
        ->and(OnboardingStep::Review->isDataStep())->toBeFalse()
        ->and(OnboardingStep::Company->isDataStep())->toBeTrue();
});

it('advances to the next step and stops at the end', function (): void {
    expect(OnboardingStep::Company->next())->toBe(OnboardingStep::Industry)
        ->and(OnboardingStep::Pricing->next())->toBe(OnboardingStep::Review)
        ->and(OnboardingStep::Review->next())->toBeNull()
        ->and(OnboardingStep::first())->toBe(OnboardingStep::Company);
});

it('reports a 1-based position', function (): void {
    expect(OnboardingStep::Company->position())->toBe(1)
        ->and(OnboardingStep::Review->position())->toBe(6);
});
