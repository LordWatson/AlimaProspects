<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Enrichment\Http\Controllers\Api\V1\EnrichmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Scoped with the **leads** abilities: a contact is part of a lead, and a credential that may
    // write to a team's leads may spend on finding their details (ADR 0013 — a scope asks what a
    // credential may do).
    Route::post('leads/{lead}/enrich', [EnrichmentController::class, 'store'])
        ->whereNumber('lead')
        ->middleware(['throttle:enrichment', 'ability:'.TokenAbility::LeadsWrite->value])
        ->name('enrichment.store');

    Route::post('leads/{lead}/contacts/{contact}/reveal-email', [EnrichmentController::class, 'revealEmail'])
        ->whereNumber('lead')
        ->whereNumber('contact')
        ->middleware(['throttle:enrichment', 'ability:'.TokenAbility::LeadsWrite->value])
        ->name('enrichment.reveal-email');

    Route::post('leads/{lead}/contacts/{contact}/reveal-phone', [EnrichmentController::class, 'revealPhone'])
        ->whereNumber('lead')
        ->whereNumber('contact')
        ->middleware(['throttle:enrichment', 'ability:'.TokenAbility::LeadsWrite->value])
        ->name('enrichment.reveal-phone');
});
