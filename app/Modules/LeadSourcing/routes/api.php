<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\LeadSourcing\Http\Controllers\Api\V1\DiscoveryController;
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
});
