<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\Web\InvitationAcceptanceController;
use App\Modules\Auth\Http\Controllers\Web\Settings\ApiTokenController;
use App\Modules\Auth\Http\Controllers\Web\TeamController;
use App\Modules\Auth\Http\Controllers\Web\TeamInvitationController;
use App\Modules\Auth\Http\Controllers\Web\TeamMemberController;
use App\Modules\Auth\Http\Controllers\Web\TeamSettingsController;
use App\Modules\Auth\Http\Controllers\Web\TeamSwitchController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    // Team settings — act on the viewer's active team.
    Route::get('settings/team', [TeamSettingsController::class, 'show'])->name('team.settings');
    Route::patch('settings/team', [TeamController::class, 'update'])->name('team.update');

    Route::post('teams', [TeamController::class, 'store'])->name('teams.store');
    Route::post('teams/{team}/switch', TeamSwitchController::class)->name('teams.switch');

    // Invitations (manage).
    Route::post('settings/team/invitations', [TeamInvitationController::class, 'store'])->name('team.invitations.store');
    Route::post('settings/team/invitations/{invitation}/resend', [TeamInvitationController::class, 'resend'])->name('team.invitations.resend');
    Route::delete('settings/team/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('team.invitations.destroy');

    // Members.
    Route::patch('settings/team/members/{member}', [TeamMemberController::class, 'update'])->name('team.members.update');
    Route::delete('settings/team/members/{member}', [TeamMemberController::class, 'destroy'])->name('team.members.destroy');

    // Personal API tokens (web management for the mobile app).
    Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    // `capability:api_access` (Phase 19b). **Issuing** only: the index and the revoke stay open on
    // every plan, because a team that has downgraded still needs to see and clean up the tokens it
    // already holds — taking away the ability to revoke would be the wrong lever entirely.
    Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])
        ->middleware('capability:api_access')
        ->name('api-tokens.store');
    Route::delete('settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    // Accept an invitation (the signed landing GET is below).
    Route::post('invitations/{token}', [InvitationAcceptanceController::class, 'accept'])->name('invitations.accept');
});

// Signed, time-limited invitation landing. Auth is still required to accept.
Route::middleware(['auth', 'signed'])
    ->get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])
    ->name('invitations.show');
