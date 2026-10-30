<?php

declare(strict_types=1);

use App\Modules\BusinessIntelligence\Http\Controllers\Web\BusinessIntelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('business-intel', [BusinessIntelController::class, 'show'])->name('business-intel.show');
    Route::post('business-intel/rerun', [BusinessIntelController::class, 'rerun'])->name('business-intel.rerun');
    Route::post('business-intel/{report}/accept', [BusinessIntelController::class, 'accept'])
        ->whereNumber('report')
        ->name('business-intel.accept');
});
