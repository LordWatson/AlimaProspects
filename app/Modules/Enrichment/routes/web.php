<?php

declare(strict_types=1);

use App\Modules\Enrichment\Http\Controllers\Web\EnrichmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    // Asking a vendor to look, and spending to see what it found (PL3). Throttled per member on
    // top of the team's allowance: the allowance stops a month's spend, and this stops a loop.
    Route::post('leads/{lead}/enrich', [EnrichmentController::class, 'store'])
        ->whereNumber('lead')
        ->middleware('throttle:enrichment')
        ->name('enrichment.store');

    Route::post('leads/enrich', [EnrichmentController::class, 'bulk'])
        ->middleware('throttle:enrichment')
        ->name('enrichment.bulk');

    Route::post('leads/{lead}/contacts/{contact}/reveal-email', [EnrichmentController::class, 'revealEmail'])
        ->whereNumber('lead')
        ->whereNumber('contact')
        ->middleware('throttle:enrichment')
        ->name('enrichment.reveal-email');

    Route::post('leads/{lead}/contacts/{contact}/reveal-phone', [EnrichmentController::class, 'revealPhone'])
        ->whereNumber('lead')
        ->whereNumber('contact')
        ->middleware('throttle:enrichment')
        ->name('enrichment.reveal-phone');
});
