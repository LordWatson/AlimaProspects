<?php

declare(strict_types=1);

use App\Modules\AiInsights\Http\Controllers\Api\V1\InsightController;
use Illuminate\Support\Facades\Route;

/*
 * AI Insights API v1 routes (Sanctum, mounted at /api/v1 by the module base provider, Phase 14).
 * Parity with the web screen, and the same Actions behind both.
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('insights', [InsightController::class, 'index'])->name('insights.index');
    Route::get('insights/{insight}', [InsightController::class, 'show'])
        ->whereNumber('insight')
        ->name('insights.show');
    Route::post('insights/{insight}/dismiss', [InsightController::class, 'dismiss'])
        ->whereNumber('insight')
        ->name('insights.dismiss');
});
