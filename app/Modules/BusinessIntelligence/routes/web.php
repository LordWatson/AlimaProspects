<?php

declare(strict_types=1);

use App\Modules\BusinessIntelligence\Http\Controllers\Web\BusinessIntelController;
use Illuminate\Support\Facades\Route;

/*
 * `capability:` is the **courtesy** half of plan gating (Phase 19b, ADR 0015): a member whose plan
 * does not include this gets a sentence about their plan rather than a half-rendered screen. The
 * guard inside the Action is what makes the refusal true, because the same work is reachable from a
 * queued job and the scheduler, which have no request to put middleware on. Both ask the same
 * `CapabilityGate`, so they cannot disagree.
 */
Route::middleware(['auth', 'verified', 'capability:business_intelligence'])->group(function (): void {
    Route::get('business-intel', [BusinessIntelController::class, 'show'])->name('business-intel.show');
    Route::post('business-intel/rerun', [BusinessIntelController::class, 'rerun'])->name('business-intel.rerun');
    Route::post('business-intel/{report}/accept', [BusinessIntelController::class, 'accept'])
        ->whereNumber('report')
        ->name('business-intel.accept');
});
