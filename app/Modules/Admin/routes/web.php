<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminAbility;
use App\Modules\Admin\Http\Controllers\Web\AnnouncementController;
use App\Modules\Admin\Http\Controllers\Web\AnnouncementDismissalController;
use App\Modules\Admin\Http\Controllers\Web\DashboardController;
use App\Modules\Admin\Http\Controllers\Web\ImpersonationController;
use App\Modules\Admin\Http\Controllers\Web\ProvisioningController;
use App\Modules\Admin\Http\Controllers\Web\ResourceController;
use App\Modules\Admin\Http\Controllers\Web\SettingsController;
use App\Modules\Admin\Http\Controllers\Web\TeamBillingController;
use App\Modules\Admin\Http\Controllers\Web\TeamOperationsController;
use App\Modules\Admin\Http\Controllers\Web\UserOperationsController;
use App\Modules\Admin\Http\Middleware\AdminIpAllowlist;
use App\Modules\Admin\Http\Middleware\EnsureAdminTwoFactor;
use App\Modules\Admin\Http\Middleware\EnsurePlatformAdmin;
use App\Modules\Admin\Http\Middleware\PlatformContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin panel (Phase 19a, ADR 0014)
|--------------------------------------------------------------------------
|
| **Web only, by decision.** There is no `/api/v1/admin`: a token that can read every tenant is a
| liability no MVP ops task needs, and it would have to be scoped, versioned and specified like
| everything else in ADR 0013. This module therefore ships no `routes/api.php` at all — the absence
| is the decision, not an oversight.
|
| Every route below is inside one stack, and `AdminRouteContractTest` asserts over the whole route
| table that it stays that way: `auth`, then the five-part admin stack, then exactly one
| `admin.can:` ability per route. One forgotten middleware on one route is the entire vulnerability,
| and a per-route test only covers the routes somebody remembered to write one for.
|
| The order of the stack matters:
|
|   1. `auth`                 — somebody is signed in.
|   2. `EnsurePlatformAdmin`  — and they are an operator. 404 to everybody else, so a customer who
|                               guesses the path learns nothing; a 403 would confirm it is there.
|   3. `AdminIpAllowlist`     — from a permitted network, when one is configured.
|   4. `EnsureAdminTwoFactor` — with a second factor. Redirects to enrolment, so it must run after
|                               (2): a customer must get the 404, not a trip to a security page.
|   5. `PlatformContext`      — and only now is tenant scoping removed.
|   6. `throttle:admin`       — its own limiter, not the app's.
|
*/

Route::middleware([
    'auth',
    EnsurePlatformAdmin::class,
    AdminIpAllowlist::class,
    EnsureAdminTwoFactor::class,
    PlatformContext::class,
    'throttle:admin',
])
    ->prefix((string) config('admin.path', 'admin'))
    ->name('admin.')
    ->group(function (): void {

        Route::get('/', DashboardController::class)
            ->middleware('admin.can:'.AdminAbility::Access->value)
            ->name('dashboard');

        /*
         * The platform switches (Phase 19b). Behind `settings.manage`, which only a super admin
         * holds: these are the levers that change behaviour for every tenant at once.
         */
        Route::get('settings', [SettingsController::class, 'edit'])
            ->middleware('admin.can:'.AdminAbility::ManageSettings->value)
            ->name('settings.edit');

        Route::patch('settings', [SettingsController::class, 'update'])
            ->middleware('admin.can:'.AdminAbility::ManageSettings->value)
            ->name('settings.update');

        /*
         * Announcements (Phase 19b). `settings.manage`: an announcement is read by every tenant it
         * targets, which makes publishing one a platform-wide act rather than a support task.
         */
        Route::prefix('announcements')->name('announcements.')->group(function (): void {
            Route::get('/', [AnnouncementController::class, 'index'])
                ->middleware('admin.can:'.AdminAbility::ManageSettings->value)
                ->name('index');

            Route::post('/', [AnnouncementController::class, 'store'])
                ->middleware('admin.can:'.AdminAbility::ManageSettings->value)
                ->name('store');

            Route::delete('{announcement}', [AnnouncementController::class, 'retire'])
                ->middleware('admin.can:'.AdminAbility::ManageSettings->value)
                ->name('retire');
        });

        /*
         * Onboarding a business (Phase 19b). `preview` is the dry run and writes nothing, but it is
         * behind the same ability as the real thing: it reveals whether an address already belongs
         * to an account, which is not a question an operator without provisioning rights should be
         * able to ask of arbitrary addresses.
         */
        Route::prefix('provisioning')->name('provisioning.')->group(function (): void {
            Route::get('/', [ProvisioningController::class, 'create'])
                ->middleware('admin.can:'.AdminAbility::ManageTeams->value)
                ->name('create');

            Route::post('preview', [ProvisioningController::class, 'preview'])
                ->middleware('admin.can:'.AdminAbility::ManageTeams->value)
                ->name('preview');

            Route::post('/', [ProvisioningController::class, 'store'])
                ->middleware('admin.can:'.AdminAbility::ManageTeams->value)
                ->name('store');
        });

        /*
         * The two bespoke screens. The registry handles the forty tables nobody needs a judgement
         * about; these handle the two somebody always does — and both call the owning module's
         * Actions rather than writing rows, so the last-Owner rules still hold.
         */
        Route::prefix('teams/{team}')->name('teams.')->group(function (): void {
            Route::get('/', [TeamOperationsController::class, 'show'])
                ->middleware('admin.can:'.AdminAbility::ReadTenantData->value)
                ->name('show');

            Route::post('suspend', [TeamOperationsController::class, 'suspend'])
                ->middleware('admin.can:'.AdminAbility::ManageTeams->value)
                ->name('suspend');

            Route::post('restore', [TeamOperationsController::class, 'restore'])
                ->middleware('admin.can:'.AdminAbility::ManageTeams->value)
                ->name('restore');

            Route::patch('members/{member}', [TeamOperationsController::class, 'updateMemberRole'])
                ->middleware('admin.can:'.AdminAbility::ManageTeams->value)
                ->name('members.update');

            Route::delete('members/{member}', [TeamOperationsController::class, 'removeMember'])
                ->middleware('admin.can:'.AdminAbility::ManageTeams->value)
                ->name('members.destroy');

            /*
             * Billing and capabilities for one tenant (Phase 19b). Reading is `billing.manage`
             * rather than `tenant.read`, because the screen is about money and the Finance role is
             * the one that needs it; writing is the same ability, because somebody who can see a
             * team's plan is the person who should be able to fix it.
             */
            Route::get('billing', [TeamBillingController::class, 'show'])
                ->middleware('admin.can:'.AdminAbility::ManageBilling->value)
                ->name('billing');

            Route::patch('billing/mode', [TeamBillingController::class, 'setMode'])
                ->middleware('admin.can:'.AdminAbility::ManageBilling->value)
                ->name('billing.mode');

            Route::post('billing/grants', [TeamBillingController::class, 'grant'])
                ->middleware('admin.can:'.AdminAbility::ManageBilling->value)
                ->name('billing.grants.store');

            Route::delete('billing/grants/{grant}', [TeamBillingController::class, 'revokeGrant'])
                ->middleware('admin.can:'.AdminAbility::ManageBilling->value)
                ->name('billing.grants.destroy');

            /*
             * Capability overrides. `settings.manage`, not `billing.manage`: overriding what a plan
             * includes is a product decision rather than a billing correction, and it beats the
             * plan — so it sits with the other levers only a super admin holds.
             */
            Route::post('capabilities', [TeamBillingController::class, 'setCapability'])
                ->middleware('admin.can:'.AdminAbility::ManageSettings->value)
                ->name('capabilities.store');

            Route::delete('capabilities/{capability}', [TeamBillingController::class, 'clearCapability'])
                ->middleware('admin.can:'.AdminAbility::ManageSettings->value)
                ->name('capabilities.destroy');
        });

        Route::prefix('users/{user}')->name('users.')->group(function (): void {
            Route::get('/', [UserOperationsController::class, 'show'])
                ->middleware('admin.can:'.AdminAbility::ReadTenantData->value)
                ->name('show');

            Route::post('password-reset', [UserOperationsController::class, 'sendPasswordReset'])
                ->middleware('admin.can:'.AdminAbility::ManageUsers->value)
                ->name('password-reset');

            Route::post('two-factor-reset', [UserOperationsController::class, 'resetTwoFactor'])
                ->middleware('admin.can:'.AdminAbility::ManageUsers->value)
                ->name('two-factor-reset');

            Route::post('revoke-sessions', [UserOperationsController::class, 'revokeSessions'])
                ->middleware('admin.can:'.AdminAbility::ManageUsers->value)
                ->name('revoke-sessions');

            Route::post('revoke-tokens', [UserOperationsController::class, 'revokeTokens'])
                ->middleware('admin.can:'.AdminAbility::ManageUsers->value)
                ->name('revoke-tokens');

            Route::post('verify-email', [UserOperationsController::class, 'verifyEmail'])
                ->middleware('admin.can:'.AdminAbility::ManageUsers->value)
                ->name('verify-email');

            // Starting is an admin act; stopping is not — see ImpersonationController.
            Route::post('impersonate', [ImpersonationController::class, 'start'])
                ->middleware('admin.can:'.AdminAbility::Impersonate->value)
                ->name('impersonate');
        });

        /*
         * The registry. Declared **last** so its `{resource}` placeholder cannot swallow
         * `teams/...` or `users/...`, and `create` is declared before `{id}` for the same reason in
         * miniature. There is no implicit model binding here: which model a path refers to is not
         * known until the registry has resolved the descriptor.
         */
        Route::prefix('resources/{resource}')->name('resources.')->group(function (): void {
            Route::get('/', [ResourceController::class, 'index'])
                ->middleware('admin.can:'.AdminAbility::ReadTenantData->value)
                ->name('index');

            Route::get('create', [ResourceController::class, 'create'])
                ->middleware('admin.can:'.AdminAbility::WriteTenantData->value)
                ->name('create');

            Route::post('/', [ResourceController::class, 'store'])
                ->middleware('admin.can:'.AdminAbility::WriteTenantData->value)
                ->name('store');

            Route::get('{id}', [ResourceController::class, 'show'])
                ->middleware('admin.can:'.AdminAbility::ReadTenantData->value)
                ->name('show');

            Route::get('{id}/edit', [ResourceController::class, 'edit'])
                ->middleware('admin.can:'.AdminAbility::WriteTenantData->value)
                ->name('edit');

            Route::patch('{id}', [ResourceController::class, 'update'])
                ->middleware('admin.can:'.AdminAbility::WriteTenantData->value)
                ->name('update');

            Route::delete('{id}', [ResourceController::class, 'destroy'])
                ->middleware('admin.can:'.AdminAbility::DeleteTenantData->value)
                ->name('destroy');
        });
    });

/*
| **Deliberately outside the stack above, and deliberately not named `admin.*`.**
|
| By the time an operator wants out of an impersonated session, `Auth::user()` is the customer — an
| account with no admin role — so an admin-gated stop route would 404 the only way back and leave
| them inside somebody else's session until the TTL expired. Its authorisation is the session
| itself: `Impersonator::stop()` refuses when there is nothing to stop.
*/
Route::middleware(['auth'])
    ->post('impersonation/stop', [ImpersonationController::class, 'stop'])
    ->name('impersonation.stop');

/*
| **The one Admin route a customer uses**, and deliberately not named `admin.*` for the same reason
| as the stop route above: the `admin.` prefix carries the whole operator stack in
| `AdminRouteContractTest`, and a tenant-facing route carrying it would either be refused outright
| or would weaken the contract for every route that needs it.
|
| Per person, not per team — one colleague clearing a banner must not hide it from colleagues who
| have not read it.
*/
Route::middleware(['auth'])
    ->post('announcements/{announcement}/dismiss', AnnouncementDismissalController::class)
    ->name('announcements.dismiss');
