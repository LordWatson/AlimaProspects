<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Signals\Http\Controllers\Api\V1\SignalController;
use App\Modules\Signals\Http\Controllers\Api\V1\SignalDefinitionController;
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
    Route::get('signals', [SignalController::class, 'index'])
        ->middleware('ability:'.TokenAbility::SignalsRead->value)
        ->name('signals.index');
    Route::get('signals/{signal}', [SignalController::class, 'show'])
        ->whereNumber('signal')
        ->middleware('ability:'.TokenAbility::SignalsRead->value)
        ->name('signals.show');
    Route::post('signals/{signal}/convert', [SignalController::class, 'convert'])
        ->whereNumber('signal')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signals.convert');
    Route::post('signals/{signal}/dismiss', [SignalController::class, 'dismiss'])
        ->whereNumber('signal')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signals.dismiss');
    Route::post('signals/{signal}/restore', [SignalController::class, 'restore'])
        ->whereNumber('signal')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signals.restore');

    // Signal definitions (S7, ADR 0018). Scoped with the module's existing abilities: a signal
    // type is part of Signals, and inventing `signal-definitions:*` would make a token that could
    // read the feed but not the types it is grouped by - a scope nobody would mean to issue.
    //
    // `presets` is before `{definition}` because the segment would otherwise be matched as an id;
    // `whereNumber` would reject it, but relying on a constraint to disambiguate a literal is the
    // kind of thing that breaks the day somebody loosens the constraint.
    Route::get('signal-definitions', [SignalDefinitionController::class, 'index'])
        ->middleware('ability:'.TokenAbility::SignalsRead->value)
        ->name('signal-definitions.index');
    Route::get('signal-definitions/presets', [SignalDefinitionController::class, 'presets'])
        ->middleware('ability:'.TokenAbility::SignalsRead->value)
        ->name('signal-definitions.presets');
    Route::post('signal-definitions', [SignalDefinitionController::class, 'store'])
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-definitions.store');
    Route::post('signal-definitions/clone', [SignalDefinitionController::class, 'clone'])
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-definitions.clone');
    Route::get('signal-definitions/{definition}', [SignalDefinitionController::class, 'show'])
        ->whereNumber('definition')
        ->middleware('ability:'.TokenAbility::SignalsRead->value)
        ->name('signal-definitions.show');
    Route::patch('signal-definitions/{definition}', [SignalDefinitionController::class, 'update'])
        ->whereNumber('definition')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-definitions.update');
    Route::delete('signal-definitions/{definition}', [SignalDefinitionController::class, 'destroy'])
        ->whereNumber('definition')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-definitions.destroy');

    Route::get('signal-watches', [SignalWatchController::class, 'index'])
        ->middleware('ability:'.TokenAbility::SignalsRead->value)
        ->name('signal-watches.index');
    Route::post('signal-watches', [SignalWatchController::class, 'store'])
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-watches.store');
    Route::get('signal-watches/{watch}', [SignalWatchController::class, 'show'])
        ->whereNumber('watch')
        ->middleware('ability:'.TokenAbility::SignalsRead->value)
        ->name('signal-watches.show');
    Route::patch('signal-watches/{watch}', [SignalWatchController::class, 'update'])
        ->whereNumber('watch')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-watches.update');
    Route::delete('signal-watches/{watch}', [SignalWatchController::class, 'destroy'])
        ->whereNumber('watch')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-watches.destroy');
    Route::post('signal-watches/{watch}/run', [SignalWatchController::class, 'run'])
        ->whereNumber('watch')
        ->middleware(['throttle:signals-run', 'ability:'.TokenAbility::SignalsWrite->value])
        ->name('signal-watches.run');
});
