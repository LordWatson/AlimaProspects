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
    // Identity, and a token retiring **itself**, carry no scope deliberately (Phase 17): a
    // credential that cannot say who it belongs to, or stand itself down when the handset is
    // wiped, is one nobody can clean up, and withholding that scopes away the safety mechanism
    // rather than any data.
    Route::get('user', [UserController::class, 'show'])->name('user.show');
    Route::delete('auth/token', [ApiTokenController::class, 'destroyCurrent'])->name('auth.token.current');

    // Reading and revoking the person's **other** tokens is a different power, and is scoped:
    // without this, a token issued to read leads could enumerate every integration on the account
    // and switch them all off.
    Route::get('auth/tokens', [ApiTokenController::class, 'index'])
        ->middleware('ability:'.TokenAbility::TokensManage->value)
        ->name('auth.tokens.index');
    Route::delete('auth/tokens/{token}', [ApiTokenController::class, 'destroy'])
        ->middleware('ability:'.TokenAbility::TokensManage->value)
        ->name('auth.tokens.destroy');

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
