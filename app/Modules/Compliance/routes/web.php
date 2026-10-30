<?php

declare(strict_types=1);

use App\Modules\Compliance\Http\Controllers\Web\ComplianceController;
use App\Modules\Compliance\Http\Controllers\Web\DsarController;
use App\Modules\Compliance\Http\Controllers\Web\LiaController;
use App\Modules\Compliance\Http\Controllers\Web\PrivacyNoticeController;
use App\Modules\Compliance\Http\Controllers\Web\TeamExportController;
use App\Modules\Compliance\Http\Controllers\Web\UnsubscribeController;
use Illuminate\Support\Facades\Route;

/*
 * Compliance & Privacy web routes (Inertia, Phase 15).
 *
 * Two groups, and the division is the module's whole shape:
 *
 * - **The team's own screens** sit behind `auth`+`verified` under `compliance/`: the centre, the
 *   legitimate-interest assessment, the data export and the data-subject tooling.
 * - **Two public pages** that must work for people who are not users at all — the hosted privacy
 *   notice every outreach footer links to, and the one-click unsubscribe endpoint. A privacy notice
 *   behind a login is not a privacy notice, and an opt-out a recipient cannot reach is an opt-out
 *   honoured by nobody (docs/compliance.md §3.3, §3.7).
 *
 * `export` is rate-limited because it reads every table the team owns — the heaviest thing a member
 * can ask for. The unsubscribe routes are **signed**; the POST is additionally exempt from CSRF
 * (bootstrap/app.php), because a mail client acting on `List-Unsubscribe-Post` has no session.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('compliance', [ComplianceController::class, 'index'])
        ->name('compliance.index');

    Route::patch('compliance/retention', [ComplianceController::class, 'retention'])
        ->name('compliance.retention.update');

    Route::get('compliance/lawful-basis', [LiaController::class, 'edit'])
        ->name('compliance.lia.edit');
    Route::put('compliance/lawful-basis', [LiaController::class, 'update'])
        ->name('compliance.lia.update');
    Route::delete('compliance/lawful-basis', [LiaController::class, 'withdraw'])
        ->name('compliance.lia.withdraw');

    Route::post('compliance/export', [TeamExportController::class, 'store'])
        ->middleware('throttle:compliance-export')
        ->name('compliance.export.store');

    Route::get('compliance/requests', [DsarController::class, 'index'])
        ->name('compliance.dsar.index');
    Route::delete('compliance/requests', [DsarController::class, 'erase'])
        ->name('compliance.dsar.erase');
});

/*
 * Public — no auth, no team context.
 */
Route::get('privacy', [PrivacyNoticeController::class, 'show'])->name('privacy-notice');

// `done` is declared first and the id is constrained to digits, so the two cannot collide.
Route::get('unsubscribe/done', [UnsubscribeController::class, 'done'])->name('unsubscribe.done');

// Metered as well as signed. The signature already rules out enumeration, but every POST writes a
// suppression and an audit entry, and an unauthenticated endpoint that writes should not be
// unbounded — keyed by IP, since there is no account to key on.
Route::middleware(['signed', 'throttle:compliance-unsubscribe'])->group(function (): void {
    Route::get('unsubscribe/{message}', [UnsubscribeController::class, 'show'])
        ->whereNumber('message')
        ->name('unsubscribe.show');

    Route::post('unsubscribe/{message}', [UnsubscribeController::class, 'store'])
        ->whereNumber('message')
        ->name('unsubscribe.store');
});
