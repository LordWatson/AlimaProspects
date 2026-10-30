<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Http\Controllers\Api\V1\DiscoveryController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('discovery-runs', [DiscoveryController::class, 'index'])->name('lead-sourcing.runs.index');
    Route::post('discovery-runs', [DiscoveryController::class, 'store'])->name('lead-sourcing.runs.store');
    Route::get('discovery-runs/{run}', [DiscoveryController::class, 'show'])
        ->whereNumber('run')
        ->name('lead-sourcing.runs.show');
});
