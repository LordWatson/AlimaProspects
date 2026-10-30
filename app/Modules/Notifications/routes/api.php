<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\Api\V1\NotificationController;
use App\Modules\Notifications\Http\Controllers\Api\V1\PushDeviceController;
use Illuminate\Support\Facades\Route;

/*
 * Notifications API v1 routes (Sanctum, mounted at /api/v1 by the base provider, Phase 12).
 *
 * `unread` is its own endpoint because a badge is polled far more often than a list is opened, and
 * a count is a fraction of the cost.
 *
 * The push-device routes are API-only: a registration token comes from the mobile app's runtime and
 * there is nothing for a web form to post.
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/unread', [NotificationController::class, 'unread'])
        ->name('notifications.unread');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('notifications.read');

    Route::get('push-devices', [PushDeviceController::class, 'index'])->name('push-devices.index');
    Route::post('push-devices', [PushDeviceController::class, 'store'])->name('push-devices.store');
    Route::delete('push-devices', [PushDeviceController::class, 'destroy'])->name('push-devices.destroy');
});
