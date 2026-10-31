<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Onboarding\Http\Controllers\Api\V1\OnboardingController;
use Illuminate\Support\Facades\Route;

/*
 * Setup state is a fact about the team, so it carries the team read scope (Phase 17).
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('onboarding', [OnboardingController::class, 'show'])
        ->middleware('ability:'.TokenAbility::TeamRead->value)
        ->name('onboarding.show');
});
