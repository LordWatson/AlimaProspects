<?php

declare(strict_types=1);

use App\Modules\Outreach\Http\Controllers\Web\OutreachController;
use App\Modules\Outreach\Http\Controllers\Web\OutreachTemplateController;
use App\Modules\Outreach\Http\Controllers\Web\SuppressionController;
use Illuminate\Support\Facades\Route;

/*
 * Outreach web routes (Inertia, Phase 11).
 *
 * The draft screen is nested under the lead — `leads/{lead}/outreach` — because that is what it is
 * about, and a member arrives at it from the lead rather than from the nav. The briefs and the
 * do-not-contact list are team-level settings and sit under `outreach/`.
 *
 * `draft` is rate-limited because it spends AI credit, and `send` because it spends the platform's
 * sending reputation. Both are per member rather than per IP, so a shared office doesn't throttle
 * itself.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('leads/{lead}/outreach', [OutreachController::class, 'show'])
        ->whereNumber('lead')
        ->name('outreach.show');

    Route::post('leads/{lead}/outreach/draft', [OutreachController::class, 'draft'])
        ->whereNumber('lead')
        ->middleware('throttle:outreach-draft')
        ->name('outreach.draft');

    Route::post('leads/{lead}/outreach/log', [OutreachController::class, 'log'])
        ->whereNumber('lead')
        ->name('outreach.log');

    Route::patch('outreach/messages/{message}', [OutreachController::class, 'update'])
        ->whereNumber('message')
        ->name('outreach.messages.update');

    Route::post('outreach/messages/{message}/send', [OutreachController::class, 'send'])
        ->whereNumber('message')
        ->middleware('throttle:outreach-send')
        ->name('outreach.messages.send');

    Route::delete('outreach/messages/{message}', [OutreachController::class, 'destroy'])
        ->whereNumber('message')
        ->name('outreach.messages.destroy');

    Route::patch('outreach/attempts/{attempt}', [OutreachController::class, 'outcome'])
        ->whereNumber('attempt')
        ->name('outreach.attempts.outcome');

    // Team-level settings.
    Route::get('outreach/templates', [OutreachTemplateController::class, 'index'])
        ->name('outreach.templates.index');
    Route::post('outreach/templates', [OutreachTemplateController::class, 'store'])
        ->name('outreach.templates.store');
    Route::patch('outreach/templates/{template}', [OutreachTemplateController::class, 'update'])
        ->whereNumber('template')
        ->name('outreach.templates.update');
    Route::delete('outreach/templates/{template}', [OutreachTemplateController::class, 'destroy'])
        ->whereNumber('template')
        ->name('outreach.templates.destroy');

    Route::get('outreach/do-not-contact', [SuppressionController::class, 'index'])
        ->name('outreach.suppressions.index');
    Route::post('outreach/do-not-contact', [SuppressionController::class, 'store'])
        ->name('outreach.suppressions.store');
    Route::delete('outreach/do-not-contact/{entry}', [SuppressionController::class, 'destroy'])
        ->whereNumber('entry')
        ->name('outreach.suppressions.destroy');
});
