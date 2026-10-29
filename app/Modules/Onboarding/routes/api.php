<?php

declare(strict_types=1);

use App\Modules\Onboarding\Http\Controllers\Api\V1\OnboardingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
});
