<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Scoring\Http\Controllers\Api\V1\ScoreController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('leads/{lead}/score', [ScoreController::class, 'show'])
        ->whereNumber('lead')
        ->middleware('ability:'.TokenAbility::ScoringRead->value)
        ->name('scoring.show');

    // A manual re-score spends AI credit, so it carries its own per-user limit on top of the
    // team's credit allowance (enforced in the AI Platform).
    Route::post('leads/{lead}/score', [ScoreController::class, 'store'])
        ->whereNumber('lead')
        ->middleware(['throttle:scoring', 'ability:'.TokenAbility::ScoringWrite->value])
        ->name('scoring.store');

    Route::post('leads/{lead}/score/feedback', [ScoreController::class, 'feedback'])
        ->whereNumber('lead')
        ->middleware('ability:'.TokenAbility::ScoringWrite->value)
        ->name('scoring.feedback');
});
