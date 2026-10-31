<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Reporting\Http\Controllers\Api\V1\ReportController;
use Illuminate\Support\Facades\Route;

/*
 * Reporting API v1 routes (Phase 17). The dashboard only: the CSV export stays web-only, because
 * what it produces is a file for a desktop, and a client that wants the numbers has them here in a
 * shape it can use.
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('reports/dashboard', [ReportController::class, 'dashboard'])
        ->middleware('ability:'.TokenAbility::ReportsRead->value)
        ->name('reports.dashboard');
});
