<?php

declare(strict_types=1);

use App\Modules\BusinessIntelligence\Http\Controllers\Api\V1\BusinessIntelController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('business-intel', [BusinessIntelController::class, 'show'])->name('business-intel.show');
});
