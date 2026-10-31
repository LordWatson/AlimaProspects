<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
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
    // Identity and the token's own lifecycle carry **no scope**, deliberately (Phase 17): a token
    // that cannot say who it belongs to, list its siblings or revoke itself is a credential nobody
    // can audit or clean up, and withholding that would scope away the safety mechanism rather
    // than any data. Everything else on /api/v1 declares an ability.
    Route::get('user', [UserController::class, 'show'])->name('user.show');

    Route::get('auth/tokens', [ApiTokenController::class, 'index'])->name('auth.tokens.index');
    Route::delete('auth/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('auth.tokens.destroy');
    Route::delete('auth/token', [ApiTokenController::class, 'destroyCurrent'])->name('auth.token.current');

    Route::get('teams', [TeamController::class, 'index'])
        ->middleware('ability:'.TokenAbility::TeamRead->value)
        ->name('teams.index');
    Route::post('teams', [TeamController::class, 'store'])
        ->middleware('ability:'.TokenAbility::TeamWrite->value)
        ->name('teams.store');
    Route::get('teams/current', [TeamController::class, 'current'])
        ->middleware('ability:'.TokenAbility::TeamRead->value)
        ->name('teams.current');
    Route::post('teams/{team}/switch', TeamSwitchController::class)
        ->middleware('ability:'.TokenAbility::TeamWrite->value)
        ->name('teams.switch');
});
