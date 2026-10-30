<?php

declare(strict_types=1);

use App\Modules\Signals\Http\Controllers\Api\V1\SignalController;
use App\Modules\Signals\Http\Controllers\Api\V1\SignalWatchController;
use Illuminate\Support\Facades\Route;

/*
 * Signals API v1 routes (Sanctum, mounted at /api/v1 by the Core base provider, S6).
 *
 * Parity with the web screens, and the same Actions behind both. `run` is additionally throttled
 * per member (`signals-run`) on top of the per-watch 15-minute window inside
 * `TriggerSignalWatchAction`, because a run spends real money on Serper and the AI and a token is
 * easier to loop than a button.
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('signals', [SignalController::class, 'index'])->name('signals.index');
    Route::get('signals/{signal}', [SignalController::class, 'show'])
        ->whereNumber('signal')
        ->name('signals.show');
    Route::post('signals/{signal}/convert', [SignalController::class, 'convert'])
        ->whereNumber('signal')
        ->name('signals.convert');
    Route::post('signals/{signal}/dismiss', [SignalController::class, 'dismiss'])
        ->whereNumber('signal')
        ->name('signals.dismiss');
    Route::post('signals/{signal}/restore', [SignalController::class, 'restore'])
        ->whereNumber('signal')
        ->name('signals.restore');

    Route::get('signal-watches', [SignalWatchController::class, 'index'])->name('signal-watches.index');
    Route::post('signal-watches', [SignalWatchController::class, 'store'])->name('signal-watches.store');
    Route::get('signal-watches/{watch}', [SignalWatchController::class, 'show'])
        ->whereNumber('watch')
        ->name('signal-watches.show');
    Route::patch('signal-watches/{watch}', [SignalWatchController::class, 'update'])
        ->whereNumber('watch')
        ->name('signal-watches.update');
    Route::delete('signal-watches/{watch}', [SignalWatchController::class, 'destroy'])
        ->whereNumber('watch')
        ->name('signal-watches.destroy');
    Route::post('signal-watches/{watch}/run', [SignalWatchController::class, 'run'])
        ->whereNumber('watch')
        ->middleware('throttle:signals-run')
        ->name('signal-watches.run');
});
