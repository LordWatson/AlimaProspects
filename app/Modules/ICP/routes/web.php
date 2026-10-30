<?php

declare(strict_types=1);

use App\Modules\ICP\Http\Controllers\Web\IcpController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('icp', [IcpController::class, 'index'])->name('icp.index');
    Route::get('icp/create', [IcpController::class, 'create'])->name('icp.create');
    Route::post('icp', [IcpController::class, 'store'])->name('icp.store');

    Route::get('icp/{icp}/edit', [IcpController::class, 'edit'])->whereNumber('icp')->name('icp.edit');
    Route::put('icp/{icp}', [IcpController::class, 'update'])->whereNumber('icp')->name('icp.update');
    Route::post('icp/{icp}/activate', [IcpController::class, 'activate'])->whereNumber('icp')->name('icp.activate');
    Route::delete('icp/{icp}', [IcpController::class, 'destroy'])->whereNumber('icp')->name('icp.destroy');
});
