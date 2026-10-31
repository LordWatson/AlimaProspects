<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\ICP\Http\Controllers\Api\V1\IcpController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('icps', [IcpController::class, 'index'])
        ->middleware('ability:'.TokenAbility::IcpRead->value)
        ->name('icps.index');
    Route::post('icps', [IcpController::class, 'store'])
        ->middleware('ability:'.TokenAbility::IcpWrite->value)
        ->name('icps.store');
    Route::get('icps/{icp}', [IcpController::class, 'show'])
        ->whereNumber('icp')
        ->middleware('ability:'.TokenAbility::IcpRead->value)
        ->name('icps.show');
    Route::put('icps/{icp}', [IcpController::class, 'update'])
        ->whereNumber('icp')
        ->middleware('ability:'.TokenAbility::IcpWrite->value)
        ->name('icps.update');
    Route::post('icps/{icp}/activate', [IcpController::class, 'activate'])
        ->whereNumber('icp')
        ->middleware('ability:'.TokenAbility::IcpWrite->value)
        ->name('icps.activate');
    Route::delete('icps/{icp}', [IcpController::class, 'destroy'])
        ->whereNumber('icp')
        ->middleware('ability:'.TokenAbility::IcpWrite->value)
        ->name('icps.destroy');
});
