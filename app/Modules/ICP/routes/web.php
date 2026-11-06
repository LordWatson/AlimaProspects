<?php

declare(strict_types=1);

use App\Modules\ICP\Http\Controllers\Web\IcpController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('icp', [IcpController::class, 'index'])->name('icp.index');
    Route::get('icp/create', [IcpController::class, 'create'])->name('icp.create');
    Route::post('icp', [IcpController::class, 'store'])->name('icp.store');

    // The composer's only path, throttled per member for the reason every paid button in this
    // codebase is: a held-down key spends money. Ten an hour is generous for a screen a team
    // visits a handful of times, and the team's own ceiling is the AI credit allowance.
    Route::post('icp/compose', [IcpController::class, 'compose'])
        ->middleware('throttle:10,60')
        ->name('icp.compose');

    Route::get('icp/{icp}/edit', [IcpController::class, 'edit'])->whereNumber('icp')->name('icp.edit');
    Route::put('icp/{icp}', [IcpController::class, 'update'])->whereNumber('icp')->name('icp.update');
    Route::post('icp/{icp}/activate', [IcpController::class, 'activate'])->whereNumber('icp')->name('icp.activate');
    Route::delete('icp/{icp}', [IcpController::class, 'destroy'])->whereNumber('icp')->name('icp.destroy');
});
