<?php

declare(strict_types=1);

use App\Modules\AiInsights\Http\Controllers\Web\InsightController;
use Illuminate\Support\Facades\Route;

/*
 * AI Insights web routes (Inertia, Phase 14). The feed, and dismissing one.
 *
 * There is no "generate now" endpoint, deliberately. A run spends AI credit, and insights are a
 * daily rhythm rather than something to refresh — a button would be a way for a member to spend
 * the team's allowance on the same numbers twice. Generation is the schedule's job, and
 * `insights:generate --team=` is there for a demo or a support request (see the module doc).
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('insights', [InsightController::class, 'index'])->name('insights.index');

    Route::post('insights/{insight}/dismiss', [InsightController::class, 'dismiss'])
        ->whereNumber('insight')
        ->name('insights.dismiss');
});
