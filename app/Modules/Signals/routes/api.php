<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Core\Enums\Capability;
use App\Modules\Signals\Http\Controllers\Api\V1\SignalController;
use App\Modules\Signals\Http\Controllers\Api\V1\SignalDefinitionController;
use App\Modules\Signals\Http\Controllers\Api\V1\SignalEventController;
use App\Modules\Signals\Http\Controllers\Api\V1\SignalPlaybookController;
use App\Modules\Signals\Http\Controllers\Api\V1\SignalWatchController;
use App\Modules\Signals\Http\Controllers\Api\V1\SignalWatchlistController;
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
    /*
     * Pushed events (S13b, ADR 0023 §7) — the only path into this module that originates outside
     * our own code.
     *
     * Four controls on the route itself, before the request's own six: the token's ability, the
     * plan capability (a webhook is part of the API, so it follows API access), and **its own rate
     * limiter** rather than the shared `api` one, because a machine retrying in a loop must not be
     * able to lock a customer out of every other endpoint.
     */
    Route::post('signal-events', [SignalEventController::class, 'store'])
        ->middleware([
            'ability:'.TokenAbility::SignalsWrite->value,
            'capability:'.Capability::ApiAccess->value,
            'throttle:signals-events',
        ])
        ->name('signal-events.store');

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
    Route::post('signals/{signal}/attach', [SignalController::class, 'attach'])
        ->whereNumber('signal')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signals.attach');
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

    // Both before `{definition}` for the same reason `presets` is: a literal segment that could be
    // read as an id should not depend on a numeric constraint to disambiguate it.
    //
    // `SignalsWrite` rather than a read ability, although neither creates a row: both spend the
    // team's money, and a scope asks what a *credential* may do (ADR 0013). A read-only token that
    // could run paid AI drafts is not a read-only token.
    Route::post('signal-definitions/compose', [SignalDefinitionController::class, 'compose'])
        ->middleware(['throttle:signals-compose', 'ability:'.TokenAbility::SignalsWrite->value])
        ->name('signal-definitions.compose');
    Route::post('signal-definitions/preview', [SignalDefinitionController::class, 'preview'])
        ->middleware(['throttle:signals-preview', 'ability:'.TokenAbility::SignalsWrite->value])
        ->name('signal-definitions.preview');
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

    /*
     * Playbooks (S14). `SignalsRead`/`SignalsWrite` rather than a scope of their own, for the same
     * reason the definition and watchlist routes share them: a playbook is part of Signals, and a
     * token that could read the feed but not the rules acting on it is a credential nobody would
     * mean to issue.
     *
     * `runs` and `preview` are both before `{playbook}`, so a literal segment is never read as an
     * id. `preview` takes a **write** ability although it spends nothing and changes nothing: a
     * scope asks what a credential may do (ADR 0013), and previewing means posting a rule — there
     * is nothing here for a read-only token to preview.
     */
    Route::get('signal-playbooks', [SignalPlaybookController::class, 'index'])
        ->middleware('ability:'.TokenAbility::SignalsRead->value)
        ->name('signal-playbooks.index');
    Route::get('signal-playbooks/runs', [SignalPlaybookController::class, 'runs'])
        ->middleware('ability:'.TokenAbility::SignalsRead->value)
        ->name('signal-playbooks.runs');
    Route::post('signal-playbooks/preview', [SignalPlaybookController::class, 'preview'])
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-playbooks.preview');
    Route::post('signal-playbooks', [SignalPlaybookController::class, 'store'])
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-playbooks.store');
    Route::get('signal-playbooks/{playbook}', [SignalPlaybookController::class, 'show'])
        ->whereNumber('playbook')
        ->middleware('ability:'.TokenAbility::SignalsRead->value)
        ->name('signal-playbooks.show');
    Route::patch('signal-playbooks/{playbook}', [SignalPlaybookController::class, 'update'])
        ->whereNumber('playbook')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-playbooks.update');
    Route::delete('signal-playbooks/{playbook}', [SignalPlaybookController::class, 'destroy'])
        ->whereNumber('playbook')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-playbooks.destroy');

    // Watchlists (S10). `SignalsRead`/`SignalsWrite` rather than a scope of their own: a
    // watchlist is where a watch looks, and a token that could read the feed but not the accounts
    // it is about is a credential nobody would mean to issue.
    //
    // `entries` is a **bulk** add, bounded by the list's own cap, because a team with a CRM has
    // this list somewhere else already and typing it into a textarea is the worse answer.
    Route::get('signal-watchlists', [SignalWatchlistController::class, 'index'])
        ->middleware('ability:'.TokenAbility::SignalsRead->value)
        ->name('signal-watchlists.index');
    Route::post('signal-watchlists', [SignalWatchlistController::class, 'store'])
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-watchlists.store');
    Route::get('signal-watchlists/{watchlist}', [SignalWatchlistController::class, 'show'])
        ->whereNumber('watchlist')
        ->middleware('ability:'.TokenAbility::SignalsRead->value)
        ->name('signal-watchlists.show');
    Route::patch('signal-watchlists/{watchlist}', [SignalWatchlistController::class, 'update'])
        ->whereNumber('watchlist')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-watchlists.update');
    Route::delete('signal-watchlists/{watchlist}', [SignalWatchlistController::class, 'destroy'])
        ->whereNumber('watchlist')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-watchlists.destroy');
    Route::post('signal-watchlists/{watchlist}/entries', [SignalWatchlistController::class, 'storeEntries'])
        ->whereNumber('watchlist')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-watchlists.entries.store');
    Route::delete('signal-watchlists/{watchlist}/entries/{entry}', [SignalWatchlistController::class, 'destroyEntry'])
        ->whereNumber('watchlist')
        ->whereNumber('entry')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-watchlists.entries.destroy');
    Route::post('signal-watchlists/{watchlist}/sync', [SignalWatchlistController::class, 'sync'])
        ->whereNumber('watchlist')
        ->middleware('ability:'.TokenAbility::SignalsWrite->value)
        ->name('signal-watchlists.sync');

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
