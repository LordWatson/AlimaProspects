<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\LeadSourcing\Http\Controllers\Api\V1\DiscoveryController;
use App\Modules\LeadSourcing\Http\Controllers\Api\V1\EvidenceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('discovery-runs', [DiscoveryController::class, 'index'])
        ->middleware('ability:'.TokenAbility::DiscoveryRead->value)
        ->name('lead-sourcing.runs.index');
    Route::post('discovery-runs', [DiscoveryController::class, 'store'])
        ->middleware('ability:'.TokenAbility::DiscoveryWrite->value)
        ->name('lead-sourcing.runs.store');
    Route::get('discovery-runs/{run}', [DiscoveryController::class, 'show'])
        ->whereNumber('run')
        ->middleware('ability:'.TokenAbility::DiscoveryRead->value)
        ->name('lead-sourcing.runs.show');

    // Why now (PL1). Scoped with the **leads** abilities rather than discovery's: evidence is a
    // property of a lead, and a credential that may read a team's leads may read why they are on
    // the list (ADR 0013 — a scope asks what a credential may do).
    Route::get('leads/{lead}/evidence', [EvidenceController::class, 'index'])
        ->whereNumber('lead')
        ->middleware('ability:'.TokenAbility::LeadsRead->value)
        ->name('lead-sourcing.evidence.index');

    Route::post('leads/{lead}/evidence/{evidence}/feedback', [EvidenceController::class, 'feedback'])
        ->whereNumber('lead')
        ->whereNumber('evidence')
        ->middleware('ability:'.TokenAbility::LeadsWrite->value)
        ->name('lead-sourcing.evidence.feedback');
});
