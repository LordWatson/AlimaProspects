<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\Web\DashboardController;
use App\Modules\Reporting\Http\Controllers\Web\ReportExportController;
use Illuminate\Support\Facades\Route;

/*
 * Reporting web routes. The dashboard keeps the `dashboard` route name it has had since Phase 2 —
 * Fortify's redirects, the sidebar and several tests all resolve it by name.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // One click here runs every aggregation on the dashboard, so it is metered by its own limiter
    // (per member, not per IP, so a shared office doesn't throttle itself).
    Route::get('reports/export', ReportExportController::class)
        ->middleware(['throttle:reports-export', 'capability:csv_export'])
        ->name('reports.export');
});
