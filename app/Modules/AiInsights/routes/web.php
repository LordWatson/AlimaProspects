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
/*
 * `capability:` is the **courtesy** half of plan gating (Phase 19b, ADR 0015): a member whose plan
 * does not include this gets a sentence about their plan rather than a half-rendered screen. The
 * guard inside the Action is what makes the refusal true, because the same work is reachable from a
 * queued job and the scheduler, which have no request to put middleware on. Both ask the same
 * `CapabilityGate`, so they cannot disagree.
 */
Route::middleware(['auth', 'verified', 'capability:ai_insights'])->group(function (): void {
    Route::get('insights', [InsightController::class, 'index'])->name('insights.index');

    Route::post('insights/{insight}/dismiss', [InsightController::class, 'dismiss'])
        ->whereNumber('insight')
        ->name('insights.dismiss');
});
