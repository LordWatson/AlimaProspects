<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminAbility;
use App\Modules\Admin\Http\Controllers\Web\AnnouncementController;
use App\Modules\Admin\Http\Controllers\Web\AnnouncementDismissalController;
use App\Modules\Admin\Http\Controllers\Web\AuditController;
use App\Modules\Admin\Http\Controllers\Web\ComplianceActivityController;
use App\Modules\Admin\Http\Controllers\Web\DashboardController;
use App\Modules\Admin\Http\Controllers\Web\ImpersonationController;
use App\Modules\Admin\Http\Controllers\Web\OperationsController;
use App\Modules\Admin\Http\Controllers\Web\OutreachOversightController;
use App\Modules\Admin\Http\Controllers\Web\PlatformReportingController;
use App\Modules\Admin\Http\Controllers\Web\ProviderHealthController;
use App\Modules\Admin\Http\Controllers\Web\ProvisioningController;
use App\Modules\Admin\Http\Controllers\Web\ResourceController;
use App\Modules\Admin\Http\Controllers\Web\SecurityController;
use App\Modules\Admin\Http\Controllers\Web\SettingsController;
use App\Modules\Admin\Http\Controllers\Web\SignalLibraryController;
use App\Modules\Admin\Http\Controllers\Web\SignalRunController;
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

/*
 * **The one route without the second-factor check**, because it is where the second factor is set
 * up. Everything else in the stack still applies — signed in, an operator, from a permitted
 * network, the panel's own limiter, and `AdminAbility::Access` — and `PlatformContext` is
 * deliberately absent too: enrolment reads the operator's own account and nothing else, so there is
 * nothing here to remove tenant scoping for.
 *
 * It exists because the gate used to send a new operator to the **product's** security settings,
 * and an operator belongs to no team: every link in that shell's rail answers 404. The panel asked
 * for a second factor and handed them a room where nothing worked. `AdminRouteContractTest` names
 * this route as the exception rather than relaxing the rule for everything.
 */
Route::middleware([
    'auth',
    EnsurePlatformAdmin::class,
    AdminIpAllowlist::class,
    'throttle:admin',
])
    ->prefix((string) config('admin.path', 'admin'))
    ->name('admin.')
    ->group(function (): void {
        Route::get('security', [SecurityController::class, 'edit'])
            ->middleware('admin.can:'.AdminAbility::Access->value)
            ->name('security.edit');
    });

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
         * Platform reporting (Phase 19c) — the business's own numbers.
         *
         * Behind `reports.read`, its own ability: every figure is an aggregate and names nobody, so
         * it is not the privacy event `tenant.read` audits, and it is not only money, so putting it
         * behind `billing.manage` would have hidden the onboarding funnel from everybody but
         * Finance. The export carries the same ability as the screen — a download is the report in
         * a different window, and gating the file more strictly than the chart would stop the
         * person asked to read the numbers taking them to a meeting.
         */
        Route::prefix('reporting')->name('reporting.')->group(function (): void {
            Route::get('/', [PlatformReportingController::class, 'index'])
                ->middleware('admin.can:'.AdminAbility::ReadPlatformReports->value)
                ->name('index');

            Route::get('export', [PlatformReportingController::class, 'export'])
                ->middleware('admin.can:'.AdminAbility::ReadPlatformReports->value)
                ->name('export');
        });

        /*
         * Failed jobs, queues and the schedule (Phase 19c).
         *
         * Reading is `operations.manage` rather than `tenant.read`, which looks strict for a list
         * of job classes and is not: a failed job's exception and payload reference is the one place
         * in the panel where a tenant's internals leak out of their own screens, and the people who
         * need it are the people who can also clear it.
         *
         * Retry and delete are keyed on the **uuid**, not the numeric id: the uuid is what
         * `queue:retry` takes and what survives a retry-and-fail cycle, and a numeric id is reused
         * by the next failure.
         */
        Route::prefix('operations')->name('operations.')->group(function (): void {
            Route::get('/', [OperationsController::class, 'index'])
                ->middleware('admin.can:'.AdminAbility::ManageOperations->value)
                ->name('index');

            // Declared before `{uuid}` so the literal cannot be swallowed by the placeholder — the
            // same ordering rule the registry's `create` route follows.
            Route::post('failed-jobs/retry-all', [OperationsController::class, 'retryAll'])
                ->middleware('admin.can:'.AdminAbility::ManageOperations->value)
                ->name('failed-jobs.retry-all');

            Route::post('failed-jobs/{uuid}/retry', [OperationsController::class, 'retry'])
                ->middleware('admin.can:'.AdminAbility::ManageOperations->value)
                ->name('failed-jobs.retry');

            Route::delete('failed-jobs/{uuid}', [OperationsController::class, 'destroy'])
                ->middleware('admin.can:'.AdminAbility::ManageOperations->value)
                ->name('failed-jobs.destroy');
        });

        /*
         * Provider health (Phase 19c). Reading is `reports.read` — the error and latency rates are
         * the same aggregates the cost report shows — and the reset is `operations.manage`, because
         * overriding a breaker that exists to protect a struggling provider from us is an act
         * rather than a reading.
         */
        Route::prefix('providers')->name('providers.')->group(function (): void {
            Route::get('/', [ProviderHealthController::class, 'index'])
                ->middleware('admin.can:'.AdminAbility::ReadPlatformReports->value)
                ->name('index');

            Route::post('reset', [ProviderHealthController::class, 'reset'])
                ->middleware('admin.can:'.AdminAbility::ManageOperations->value)
                ->name('reset');
        });

        /*
         * The cross-tenant audit viewer (Phase 19c). `audit.read`, the same ability the registry's
         * `audit-logs` descriptor declares: these are other **operators'** actions as well as
         * customers'.
         *
         * **One route, and it is a GET.** `audit_logs` is append-only; there is no update and no
         * delete here or in the descriptor, and `PlatformOversightTest` asserts that over the route
         * table rather than trusting this comment.
         */
        Route::get('audit', [AuditController::class, 'index'])
            ->middleware('admin.can:'.AdminAbility::ReadAudit->value)
            ->name('audit.index');

        /*
         * The two remaining deferred operator views (Phase 19c), both read-only and both with no
         * write route anywhere:
         *
         *  - `signal_runs` across tenants (`signals-plan.md` task 6). No re-run: a run spends real
         *    money at Serper and on the AI, and an operator must not spend a tenant's allowance.
         *  - compliance activity (`compliance-privacy.md`). An erasure, an export and a retention
         *    policy are the tenant's decisions as data controller, not ours.
         */
        Route::get('signal-runs', [SignalRunController::class, 'index'])
            ->middleware('admin.can:'.AdminAbility::ReadTenantData->value)
            ->name('signal-runs.index');

        /*
         * **The signal library** (S15 task 4, plan decision #3) — the one operator surface in this
         * panel that *writes* into another module's domain, and the reason it is allowed to is that
         * it does not: every route here calls a **Signals** Action, because what a platform preset
         * is belongs to ADR 0018 §2 and not to us.
         *
         * `ManageOperations` rather than `ManagePlatformData`: curating what every tenant is offered
         * to run is the same kind of act as resetting a breaker or reading the queue — it changes
         * the platform's behaviour rather than one tenant's records.
         */
        Route::get('signal-library', [SignalLibraryController::class, 'index'])
            ->middleware('admin.can:'.AdminAbility::ManageOperations->value)
            ->name('signal-library.index');

        Route::post('signal-library/presets', [SignalLibraryController::class, 'store'])
            ->middleware('admin.can:'.AdminAbility::ManageOperations->value)
            ->name('signal-library.presets.store');

        Route::patch('signal-library/presets/{preset}', [SignalLibraryController::class, 'update'])
            ->whereNumber('preset')
            ->middleware('admin.can:'.AdminAbility::ManageOperations->value)
            ->name('signal-library.presets.update');

        // Its own route rather than a field on the editor, because it is the one change that does
        // **not** bump the version: whether the library advertises a type is not a change to the
        // type, and every clone records the version it was taken from.
        Route::post('signal-library/presets/{preset}/offered', [SignalLibraryController::class, 'setActive'])
            ->whereNumber('preset')
            ->middleware('admin.can:'.AdminAbility::ManageOperations->value)
            ->name('signal-library.presets.offered');

        Route::delete('signal-library/presets/{preset}', [SignalLibraryController::class, 'destroy'])
            ->whereNumber('preset')
            ->middleware('admin.can:'.AdminAbility::ManageOperations->value)
            ->name('signal-library.presets.destroy');

        Route::post('signal-library/submissions/{submission}/promote', [SignalLibraryController::class, 'promote'])
            ->whereNumber('submission')
            ->middleware('admin.can:'.AdminAbility::ManageOperations->value)
            ->name('signal-library.submissions.promote');

        Route::post('signal-library/submissions/{submission}/reject', [SignalLibraryController::class, 'reject'])
            ->whereNumber('submission')
            ->middleware('admin.can:'.AdminAbility::ManageOperations->value)
            ->name('signal-library.submissions.reject');

        Route::get('compliance', [ComplianceActivityController::class, 'index'])
            ->middleware('admin.can:'.AdminAbility::ReadAudit->value)
            ->name('compliance.index');

        /*
         * Outreach oversight and the **global** do-not-contact list (Phase 19c).
         *
         * Two routes, and the missing third is the decision: there is no lift route for a global
         * suppression anywhere in the product. Reading is `reports.read` (deliverability is an
         * aggregate); adding is `operations.manage`, because a platform entry stops every tenant
         * contacting somebody and whoever is on support is who needs it.
         */
        Route::prefix('outreach')->name('outreach.')->group(function (): void {
            Route::get('/', [OutreachOversightController::class, 'index'])
                ->middleware('admin.can:'.AdminAbility::ReadPlatformReports->value)
                ->name('index');

            Route::post('suppressions', [OutreachOversightController::class, 'suppress'])
                ->middleware('admin.can:'.AdminAbility::ManageOperations->value)
                ->name('suppressions.store');
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
