<?php

declare(strict_types=1);

use App\Modules\Signals\Http\Controllers\Web\SignalController;
use App\Modules\Signals\Http\Controllers\Web\SignalDefinitionController;
use App\Modules\Signals\Http\Controllers\Web\SignalWatchController;
use Illuminate\Support\Facades\Route;

/*
 * Signals web routes (Inertia, S5). The feed and the watch settings screen.
 *
 * `run` sits behind its own rate limiter as well as the per-watch 15-minute throttle in
 * `TriggerSignalWatchAction`: the throttle stops a watch being re-run, this stops a member
 * hammering every watch they have. A run costs money.
 */
/*
 * `capability:` is the **courtesy** half of plan gating (Phase 19b, ADR 0015): a member whose plan
 * does not include this gets a sentence about their plan rather than a half-rendered screen. The
 * guard inside the Action is what makes the refusal true, because the same work is reachable from a
 * queued job and the scheduler, which have no request to put middleware on. Both ask the same
 * `CapabilityGate`, so they cannot disagree.
 */
Route::middleware(['auth', 'verified', 'capability:signals'])->group(function (): void {
    Route::get('signals', [SignalController::class, 'index'])->name('signals.index');

    Route::post('signals/{signal}/convert', [SignalController::class, 'convert'])
        ->whereNumber('signal')
        ->name('signals.convert');
    Route::post('signals/{signal}/dismiss', [SignalController::class, 'dismiss'])
        ->whereNumber('signal')
        ->name('signals.dismiss');
    Route::post('signals/{signal}/restore', [SignalController::class, 'restore'])
        ->whereNumber('signal')
        ->name('signals.restore');

    // The signal-type library (S7, ADR 0018). Nested under `signals/` for the same sidebar
    // reason as the watch settings, and placed **before** `signals/watches` only incidentally -
    // both are literal segments, so there is no ordering trap between them.
    Route::get('signals/types', [SignalDefinitionController::class, 'index'])
        ->name('signal-definitions.index');
    Route::post('signals/types', [SignalDefinitionController::class, 'store'])
        ->name('signal-definitions.store');
    Route::post('signals/types/clone', [SignalDefinitionController::class, 'clone'])
        ->name('signal-definitions.clone');

    // The builder's two paid paths (S9). Both throttled per member for the same reason `run` is:
    // each press spends real money, and nothing but a person can reach either — so the ceiling
    // belongs in middleware here, while the *team's* ceilings (the AI allowance, the daily preview
    // budget) stay in the Actions where a request-less caller would also meet them.
    Route::post('signals/types/compose', [SignalDefinitionController::class, 'compose'])
        // `capability:signal_composer` as well as the Action's own check, which is the arrangement
        // every capability uses: the route gives a member a sentence about their plan, the guard
        // makes the refusal true. `CapabilityEnforcementTest` asserts that a declared capability is
        // named by a web route — a capability nobody enforces is worse than one that does not
        // exist, because the pricing page would promise a gate that is not there.
        ->middleware(['capability:signal_composer', 'throttle:signals-compose'])
        ->name('signal-definitions.compose');
    Route::post('signals/types/preview', [SignalDefinitionController::class, 'preview'])
        ->middleware('throttle:signals-preview')
        ->name('signal-definitions.preview');
    Route::patch('signals/types/{definition}', [SignalDefinitionController::class, 'update'])
        ->whereNumber('definition')
        ->name('signal-definitions.update');
    Route::delete('signals/types/{definition}', [SignalDefinitionController::class, 'destroy'])
        ->whereNumber('definition')
        ->name('signal-definitions.destroy');

    // Nested under `signals/` so the sidebar's `startsWith` match keeps Signals lit while a
    // member is in its settings — it is one destination as far as they are concerned. The route
    // *names* stay `signal-watches.*`, which is what the rest of the app refers to them by.
    Route::get('signals/watches', [SignalWatchController::class, 'index'])->name('signal-watches.index');
    Route::post('signals/watches', [SignalWatchController::class, 'store'])->name('signal-watches.store');
    Route::post('signals/watches/default', [SignalWatchController::class, 'storeDefault'])
        ->name('signal-watches.store-default');
    Route::patch('signals/watches/{watch}', [SignalWatchController::class, 'update'])
        ->whereNumber('watch')
        ->name('signal-watches.update');
    Route::delete('signals/watches/{watch}', [SignalWatchController::class, 'destroy'])
        ->whereNumber('watch')
        ->name('signal-watches.destroy');
    Route::post('signals/watches/{watch}/run', [SignalWatchController::class, 'run'])
        ->whereNumber('watch')
        ->middleware('throttle:signals-run')
        ->name('signal-watches.run');
});
