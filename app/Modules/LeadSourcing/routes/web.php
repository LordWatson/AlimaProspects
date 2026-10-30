<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Http\Controllers\Web\DiscoveryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('discovery', [DiscoveryController::class, 'index'])->name('lead-sourcing.index');
    Route::post('discovery', [DiscoveryController::class, 'store'])->name('lead-sourcing.store');
    Route::get('discovery/{run}', [DiscoveryController::class, 'show'])
        ->whereNumber('run')
        ->name('lead-sourcing.show');
});
