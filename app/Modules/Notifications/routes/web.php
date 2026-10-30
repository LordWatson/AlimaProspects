<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\Web\NotificationController;
use App\Modules\Notifications\Http\Controllers\Web\NotificationPreferenceController;
use Illuminate\Support\Facades\Route;

/*
 * Notifications web routes (Inertia, Phase 12).
 *
 * The **bell** is not here: its contents are a shared Inertia prop, so it is already on every page
 * wherever the member is (see NotificationsServiceProvider). These are the full history behind it,
 * the two read endpoints, and the settings screen.
 *
 * The settings screen sits under `settings/` so it picks up the settings layout, like every other
 * per-member preference.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');

    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('notifications.read');

    Route::get('settings/notifications', [NotificationPreferenceController::class, 'edit'])
        ->name('notifications.preferences.edit');
    Route::patch('settings/notifications', [NotificationPreferenceController::class, 'update'])
        ->name('notifications.preferences.update');
});
