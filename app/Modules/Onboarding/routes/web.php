<?php

declare(strict_types=1);

use App\Modules\Onboarding\Enums\OnboardingStep;
use App\Modules\Onboarding\Http\Controllers\Web\OnboardingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');

    Route::post('onboarding/step/{step}', [OnboardingController::class, 'saveStep'])
        ->whereIn('step', OnboardingStep::dataValues())
        ->name('onboarding.step');

    Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
});
