<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Outreach\Http\Controllers\Api\V1\OutreachController;
use App\Modules\Outreach\Http\Controllers\Api\V1\OutreachTemplateController;
use App\Modules\Outreach\Http\Controllers\Api\V1\SuppressionController;
use Illuminate\Support\Facades\Route;

/*
 * Outreach API v1 routes (Sanctum, mounted at /api/v1 by the base provider, Phase 11).
 *
 * Parity with the web screens, and the same Actions behind both. `draft` and `send` carry the same
 * per-member throttles as the web routes — a token is easier to loop than a button, and both spend
 * something real.
 *
 * The do-not-contact list is scoped with outreach rather than compliance (Phase 17): the thing a
 * token is being trusted with is whether it may change who the team is allowed to approach, which
 * is the same trust as sending.
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('leads/{lead}/outreach/messages', [OutreachController::class, 'index'])
        ->whereNumber('lead')
        ->middleware('ability:'.TokenAbility::OutreachRead->value)
        ->name('outreach.messages.index');

    Route::get('leads/{lead}/outreach/decisions', [OutreachController::class, 'decisions'])
        ->whereNumber('lead')
        ->middleware('ability:'.TokenAbility::OutreachRead->value)
        ->name('outreach.decisions');

    Route::post('leads/{lead}/outreach/draft', [OutreachController::class, 'draft'])
        ->whereNumber('lead')
        ->middleware(['throttle:outreach-draft', 'ability:'.TokenAbility::OutreachWrite->value])
        ->name('outreach.draft');

    Route::get('leads/{lead}/outreach/attempts', [OutreachController::class, 'attempts'])
        ->whereNumber('lead')
        ->middleware('ability:'.TokenAbility::OutreachRead->value)
        ->name('outreach.attempts.index');

    Route::post('leads/{lead}/outreach/attempts', [OutreachController::class, 'log'])
        ->whereNumber('lead')
        ->middleware('ability:'.TokenAbility::OutreachWrite->value)
        ->name('outreach.attempts.store');

    Route::get('outreach/messages/{message}', [OutreachController::class, 'show'])
        ->whereNumber('message')
        ->middleware('ability:'.TokenAbility::OutreachRead->value)
        ->name('outreach.messages.show');

    Route::patch('outreach/messages/{message}', [OutreachController::class, 'update'])
        ->whereNumber('message')
        ->middleware('ability:'.TokenAbility::OutreachWrite->value)
        ->name('outreach.messages.update');

    Route::post('outreach/messages/{message}/send', [OutreachController::class, 'send'])
        ->whereNumber('message')
        ->middleware(['throttle:outreach-send', 'ability:'.TokenAbility::OutreachWrite->value])
        ->name('outreach.messages.send');

    Route::delete('outreach/messages/{message}', [OutreachController::class, 'destroy'])
        ->whereNumber('message')
        ->middleware('ability:'.TokenAbility::OutreachWrite->value)
        ->name('outreach.messages.destroy');

    Route::patch('outreach/attempts/{attempt}', [OutreachController::class, 'outcome'])
        ->whereNumber('attempt')
        ->middleware('ability:'.TokenAbility::OutreachWrite->value)
        ->name('outreach.attempts.update');

    Route::get('outreach/templates', [OutreachTemplateController::class, 'index'])
        ->middleware('ability:'.TokenAbility::OutreachRead->value)
        ->name('outreach.templates.index');
    Route::post('outreach/templates', [OutreachTemplateController::class, 'store'])
        ->middleware('ability:'.TokenAbility::OutreachWrite->value)
        ->name('outreach.templates.store');
    Route::get('outreach/templates/{template}', [OutreachTemplateController::class, 'show'])
        ->whereNumber('template')
        ->middleware('ability:'.TokenAbility::OutreachRead->value)
        ->name('outreach.templates.show');
    Route::patch('outreach/templates/{template}', [OutreachTemplateController::class, 'update'])
        ->whereNumber('template')
        ->middleware('ability:'.TokenAbility::OutreachWrite->value)
        ->name('outreach.templates.update');
    Route::delete('outreach/templates/{template}', [OutreachTemplateController::class, 'destroy'])
        ->whereNumber('template')
        ->middleware('ability:'.TokenAbility::OutreachWrite->value)
        ->name('outreach.templates.destroy');

    // No lookup endpoint by design — see the controller's note on enumeration.
    Route::get('outreach/do-not-contact', [SuppressionController::class, 'index'])
        ->middleware('ability:'.TokenAbility::OutreachRead->value)
        ->name('outreach.suppressions.index');
    Route::post('outreach/do-not-contact', [SuppressionController::class, 'store'])
        ->middleware('ability:'.TokenAbility::OutreachWrite->value)
        ->name('outreach.suppressions.store');
    Route::delete('outreach/do-not-contact/{entry}', [SuppressionController::class, 'destroy'])
        ->whereNumber('entry')
        ->middleware('ability:'.TokenAbility::OutreachWrite->value)
        ->name('outreach.suppressions.destroy');
});
