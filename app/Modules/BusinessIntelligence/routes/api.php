<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\BusinessIntelligence\Http\Controllers\Api\V1\BusinessIntelController;
use Illuminate\Support\Facades\Route;

/*
 * The team's own market report reads as a report, so it carries the reports scope (Phase 17).
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('business-intel', [BusinessIntelController::class, 'show'])
        ->middleware('ability:'.TokenAbility::ReportsRead->value)
        ->name('business-intel.show');
});
