<?php

declare(strict_types=1);

namespace App\Modules\Admin\Providers;

use App\Models\User;
use App\Modules\Admin\Console\RollUpPlatformMetricsCommand;
use App\Modules\Admin\Enums\AdminAbility;
use App\Modules\Admin\Http\Responses\OperatorLoginResponse;
use App\Modules\Admin\Http\Responses\OperatorTwoFactorLoginResponse;
use App\Modules\Admin\Listeners\RecordScheduledTaskRun;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\GovernedCapabilityGate;
use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Admin\Services\SettingsStripeSwitch;
use App\Modules\Admin\Services\SuspensionAwareCreditSource;
use App\Modules\Admin\Services\SuspensionAwareLeadQuotaSource;
use App\Modules\Admin\Services\SuspensionAwareTeamSendingGate;
use App\Modules\Admin\Services\TeamSuspension;
use App\Modules\AiPlatform\Contracts\CreditSource;
use App\Modules\Billing\Contracts\StripeSwitch;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use App\Modules\ModuleServiceProvider;
use App\Modules\Outreach\Contracts\TeamSendingGate;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;

/**
 * Admin module provider (Phase 19a, ADR 0014).
 *
 * Registered **last** in `bootstrap/providers.php`, after Compliance and Billing. Those two replace
 * earlier modules' permissive stubs with the real implementations; this one goes in front of
 * whatever they left bound, which is why it uses `extend()` rather than `bind()`.
 *
 * The distinction matters. A `bind()` here would **replace** Billing's plan-backed credit source
 * with a suspension-aware one that had to know about plans — and Admin has no business knowing what
 * a plan is. An `extend()` wraps whatever is there: the inner source still answers the allowance
 * question, and Admin only intervenes when the team is suspended. Add a rule, do not take over.
 *
 * Suspension bites in **three guards**, not in middleware, and the reason is ADR 0012's: a queued
 * discovery job and a scheduled signal watch spend a team's allowance with no request behind them,
 * so a check in the request pipeline would stop a suspended team's screens and none of its
 * background spend. The three are sourcing (`LeadQuotaSource`), spending (`CreditSource`) and
 * sending (`TeamSendingGate`, which Outreach ships for this). Sign-in and reading are **not** among
 * them, deliberately: locking people out of data they own is the wrong lever and a compliance
 * problem besides.
 */
final class AdminServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ResourceRegistry::class);

        // **Where a login lands.** Fortify sends everybody to one configured path, and that path
        // assumes an account has a team — so the operator, who deliberately has none, signed in
        // and got a 404 from the dashboard's `currentTeam()->firstOrFail()`. Bound here rather
        // than changed in `config/fortify.php`, because the question is about *this* kind of
        // account and Auth has no business knowing what a platform operator is. Both responses,
        // since an operator is required to carry a second factor and would otherwise be back on
        // the 404 the day they enrolled.
        $this->app->singleton(LoginResponse::class, OperatorLoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponse::class, OperatorTwoFactorLoginResponse::class);

        // Memoised per request/job, so the decorators below can be asked inside loops.
        $this->app->scoped(TeamSuspension::class);

        // The settings store sits in front of two hot paths (the Stripe switch on every gateway
        // resolution, the kill switch on every capability check), so it is `scoped` and holds the
        // whole override set in one cache entry. See the class note.
        $this->app->scoped(PlatformSettings::class);

        $this->extendGuards();
    }

    public function boot(): void
    {
        parent::boot(); // routes

        $this->registerGates();
        $this->registerRateLimiter();
        $this->registerPlatformReporting();
        $this->registerOperatorLanding();
        $this->sharePanelPath();
    }

    /**
     * Tell the panel's own screens where the panel lives.
     *
     * `ADMIN_PATH` moves it — the runbook recommends that for production, to keep it out of every
     * scanner's logs — and the routes move with it, because they are registered under
     * `prefix(config('admin.path'))`. The **rail's links did not**: they were hard-coded
     * `/admin/...` strings, so the one deployment that hid the panel was the deployment where every
     * link in it answered 404.
     *
     * Shared rather than passed per controller: it is a property of the panel, not of a screen, and
     * twenty controllers each remembering to send it is twenty chances to forget. Scoped to
     * `admin.*` so no tenant page carries a prop about an internal tool.
     */
    private function sharePanelPath(): void
    {
        Inertia::share([
            'panel' => static fn (): ?array => request()->routeIs('admin.*')
                ? ['path' => '/'.trim((string) config('admin.path', 'admin'), '/')]
                : null,
        ]);
    }

    /**
     * Where an **already signed-in** operator is sent if they open a guest page.
     *
     * The same assumption as the login redirect, in the framework's own `guest` middleware: it
     * sends an authenticated visitor to `dashboard`, which an account with no team cannot open. So
     * an operator who opened `/login` out of habit got the same 404 the sign-in used to give them.
     *
     * Decorating the callback rather than replacing the middleware: everybody who *has* a team
     * keeps the framework's answer, which is the dashboard.
     */
    private function registerOperatorLanding(): void
    {
        RedirectIfAuthenticated::redirectUsing(
            static fn (Request $request): string => OperatorLoginResponse::panelFor($request) ?? route('dashboard'),
        );
    }

    /**
     * Phase 19c: the nightly rollup and the schedule-health listener.
     *
     * **The rollup is scheduled at 02:40, and the time is load-bearing.** Compliance's retention
     * sweep (03:40), the Signals prune (03:20) and the insights prune (03:50) all delete the rows
     * these figures are computed from, and a period that has been swept cannot be recomputed — the
     * same constraint `billing:sync-usage` runs hourly for. `PlatformRollupTest` asserts the
     * ordering against the real schedule rather than trusting this comment, because a later phase
     * moving a sweep earlier would otherwise lose the business's own numbers silently.
     *
     * The listener exists because Laravel stores **nothing** about past scheduled runs: there is no
     * table and `schedule:list` prints the next due time, not the last actual one. So "the rollup
     * has not run since Tuesday" was a fact nobody was recording. See
     * {@see RecordScheduledTaskRun}.
     */
    private function registerPlatformReporting(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RollUpPlatformMetricsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('platform:roll-up')
                ->dailyAt('02:40')
                ->withoutOverlapping()
                // One rollup per night across a fleet. The command is idempotent, so a second run
                // would be harmless rather than wrong — but it walks every tenant and every
                // ledger, and two of them racing is work nobody needs twice.
                ->onOneServer();
        });

        Event::listen(ScheduledTaskFinished::class, [RecordScheduledTaskRun::class, 'handleFinished']);
        Event::listen(ScheduledTaskFailed::class, [RecordScheduledTaskRun::class, 'handleFailed']);
        Event::listen(ScheduledTaskSkipped::class, [RecordScheduledTaskRun::class, 'handleSkipped']);
    }

    /**
     * Decorate the three guards a suspension has to reach. `extend`, not `bind` — see the class
     * note.
     */
    private function extendGuards(): void
    {
        $this->app->extend(
            CreditSource::class,
            fn (CreditSource $inner): CreditSource => new SuspensionAwareCreditSource(
                $inner,
                $this->app->make(TeamSuspension::class),
            ),
        );

        $this->app->extend(
            LeadQuotaSource::class,
            fn (LeadQuotaSource $inner): LeadQuotaSource => new SuspensionAwareLeadQuotaSource(
                $inner,
                $this->app->make(TeamSuspension::class),
            ),
        );

        $this->app->extend(
            TeamSendingGate::class,
            fn (TeamSendingGate $inner): TeamSendingGate => new SuspensionAwareTeamSendingGate(
                $inner,
                $this->app->make(TeamSuspension::class),
            ),
        );

        // Phase 19b. The operator's Stripe kill switch, in front of Billing's "is it configured".
        $this->app->extend(
            StripeSwitch::class,
            fn (StripeSwitch $inner): StripeSwitch => new SettingsStripeSwitch(
                $inner,
                $this->app->make(PlatformSettings::class),
            ),
        );

        // The outermost capability gate: **suspension beats the kill switch beats the per-team
        // override beats the plan** (ADR 0015). Billing's plan gate is what this decorates, and
        // Core's permissive default is behind that — the composition comes from provider order,
        // Admin being last.
        //
        // `scoped` through the container's own lifecycle rather than a singleton, because the gate
        // memoises per team and three of the enforcement points run inside a `queue:work` process.
        $this->app->extend(
            CapabilityGate::class,
            fn (CapabilityGate $inner): CapabilityGate => new GovernedCapabilityGate(
                $inner,
                $this->app->make(TeamSuspension::class),
                $this->app->make(PlatformSettings::class),
            ),
        );
    }

    /**
     * One gate per admin ability, named `admin.<value>`.
     *
     * Gates rather than a policy, for the reason ADR 0013 gives about token scopes: these questions
     * are not about a record. Whether an operator may read tenant data or impersonate is a property
     * of the credential, answerable before a controller has loaded anything — and most admin routes
     * have no model for a policy to receive.
     *
     * Registered for every case in the enum rather than a hand-maintained list, so a new ability
     * cannot be added to {@see AdminAbility} and left without a gate — which would be a middleware
     * string nothing answers and a 403 with no way out.
     */
    private function registerGates(): void
    {
        foreach (AdminAbility::cases() as $ability) {
            Gate::define(
                $ability->gate(),
                static fn (User $user): bool => $user->hasAdminAbility($ability),
            );
        }
    }

    /**
     * The panel's own limiter, keyed on the operator.
     *
     * Separate from the app's because the usage is different in both directions: an operator paging
     * a cross-tenant index legitimately makes far more requests than a member working one team, and
     * a compromised operator session is the one credential in this product where a scripted scrape
     * would be worth writing. Keyed on the user rather than the IP so a shared office does not
     * throttle itself — the same choice Reporting's export limiter makes.
     */
    private function registerRateLimiter(): void
    {
        RateLimiter::for('admin', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('admin.rate_limit', 240)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
