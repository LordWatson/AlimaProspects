<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\Api\V1\ApiTokenController;
use App\Modules\Auth\Http\Controllers\Api\V1\TeamController;
use App\Modules\Auth\Http\Controllers\Api\V1\TeamSwitchController;
use App\Modules\Auth\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

// Guest: exchange credentials for a personal access token (mobile sign-in). Throttled hard
// against brute force, on top of the global /api/v1 limiter.
Route::post('auth/token', [ApiTokenController::class, 'issue'])
    ->middleware('throttle:auth-token')
    ->name('auth.token.issue');

Route::middleware('auth:sanctum')->group(function (): void {
    // Sample tenant-aware endpoint for the mobile client.
    Route::get('user', [UserController::class, 'show'])->name('user.show');

    Route::get('teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('teams', [TeamController::class, 'store'])->name('teams.store');
    Route::get('teams/current', [TeamController::class, 'current'])->name('teams.current');
    Route::post('teams/{team}/switch', TeamSwitchController::class)->name('teams.switch');

    // Token management.
    Route::get('auth/tokens', [ApiTokenController::class, 'index'])->name('auth.tokens.index');
    Route::delete('auth/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('auth.tokens.destroy');
    Route::delete('auth/token', [ApiTokenController::class, 'destroyCurrent'])->name('auth.token.current');
});
