<?php

declare(strict_types=1);

use App\Modules\AiInsights\Http\Controllers\Api\V1\InsightController;
use App\Modules\Auth\Enums\TokenAbility;
use Illuminate\Support\Facades\Route;

/*
 * AI Insights API v1 routes (Sanctum, mounted at /api/v1 by the module base provider, Phase 14).
 * Parity with the web screen, and the same Actions behind both.
 *
 * Every route declares the token scope it needs (Phase 17) — see TokenAbility.
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('insights', [InsightController::class, 'index'])
        ->middleware('ability:'.TokenAbility::InsightsRead->value)
        ->name('insights.index');
    Route::get('insights/{insight}', [InsightController::class, 'show'])
        ->whereNumber('insight')
        ->middleware('ability:'.TokenAbility::InsightsRead->value)
        ->name('insights.show');
    Route::post('insights/{insight}/dismiss', [InsightController::class, 'dismiss'])
        ->whereNumber('insight')
        ->middleware('ability:'.TokenAbility::InsightsWrite->value)
        ->name('insights.dismiss');
});
