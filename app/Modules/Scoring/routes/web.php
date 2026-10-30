<?php

declare(strict_types=1);

use App\Modules\Scoring\Http\Controllers\Web\ScoreController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('leads/{lead}/score', [ScoreController::class, 'show'])
        ->whereNumber('lead')
        ->name('scoring.show');

    // A manual re-score spends AI credit, so it carries its own per-user limit on top of the
    // team's credit allowance (enforced in the AI Platform).
    Route::post('leads/{lead}/score', [ScoreController::class, 'store'])
        ->whereNumber('lead')
        ->middleware('throttle:scoring')
        ->name('scoring.store');

    Route::post('leads/{lead}/score/feedback', [ScoreController::class, 'feedback'])
        ->whereNumber('lead')
        ->name('scoring.feedback');
});
