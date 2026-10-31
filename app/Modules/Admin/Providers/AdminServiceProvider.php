<?php

declare(strict_types=1);

namespace App\Modules\Admin\Providers;

use App\Models\User;
use App\Modules\Admin\Enums\AdminAbility;
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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

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
