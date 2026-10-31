<?php

declare(strict_types=1);

use App\Modules\Signals\Http\Controllers\Web\SignalController;
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
