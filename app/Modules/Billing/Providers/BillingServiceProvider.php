<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

use App\Models\User;
use App\Modules\AiPlatform\Contracts\CreditSource;
use App\Modules\Auth\Contracts\SeatLimitSource;
use App\Modules\Billing\Console\ReportOverageCommand;
use App\Modules\Billing\Console\SyncUsageCommand;
use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Contracts\StripeSwitch;
use App\Modules\Billing\Http\Middleware\RefuseStripeWhenDisabled;
use App\Modules\Billing\Listeners\SyncSubscriptionFromStripe;
use App\Modules\Billing\Services\ConfiguredStripeSwitch;
use App\Modules\Billing\Services\DisabledBillingGateway;
use App\Modules\Billing\Services\PlanCapabilityGate;
use App\Modules\Billing\Services\PlanCreditSource;
use App\Modules\Billing\Services\PlanLeadQuotaSource;
use App\Modules\Billing\Services\PlanRegistry;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Billing\Services\PlanSeatLimit;
use App\Modules\Billing\Services\StripeBillingGateway;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use App\Modules\ModuleServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use RuntimeException;

/**
 * Billing & Plans module provider (Phase 16).
 *
 * This is where the three seams built in earlier phases are finally filled in. AI Platform's
 * `CreditSource`, Lead Sourcing's `LeadQuotaSource` and Auth's `SeatLimitSource` each shipped with
 * a permissive stub and a contract; replacing them here is a binding, not a change to any of those
 * modules — which is why nothing in AI Platform, Lead Sourcing or Auth imports
 * `App\Modules\Billing`. Registered **last** in bootstrap/providers.php so these bindings win.
 */
final class BillingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // The billable party is the tenant, not the person who clicked subscribe (ADR 0006).
        // Cashier's published migrations were replaced by module-named ones keyed on `team_id`
        // (database/migrations/2026_10_30_1700*); the package only publishes, it never loads them.
        Cashier::useCustomerModel(Team::class);

        // A failed payment does not demote a customer. Stripe retries a card for a fortnight and
        // then cancels the subscription, at which point it is cancelled and entitlement ends on
        // its own — reimplementing that window a week early would turn an expired card into a
        // support incident. Set here as well as in `SubscriptionState` so Cashier's own `valid()`
        // and `active()` agree with us rather than contradicting us from underneath.
        Cashier::keepPastDueSubscriptionsActive();

        $this->app->singleton(PlanRegistry::class);

        // `scoped`, not `singleton`: the resolver memoises per team, and a singleton would hold that
        // memo for the life of a `queue:work` process — see the class docblock.
        $this->app->scoped(PlanResolver::class);

        // Billing's own answer to “is Stripe on”: when it is configured. Admin binds a
        // settings-backed switch over it, so an operator can turn it off from a browser (Phase 19b).
        $this->app->bind(StripeSwitch::class, ConfiguredStripeSwitch::class);

        // **Resolved per call, not once.** A closure rather than a class name, so flipping the
        // switch takes effect on the next resolution instead of on the next deploy — and so a
        // deployment with no Stripe keys gets a gateway that refuses by name rather than one that
        // times out inside a checkout.
        $this->app->bind(
            BillingGateway::class,
            fn (): BillingGateway => $this->app->make(StripeSwitch::class)->isEnabled()
                ? $this->app->make(StripeBillingGateway::class)
                : $this->app->make(DisabledBillingGateway::class),
        );

        // The middle capability gate: what this team's **plan** includes (ADR 0015). Decorates
        // Core's permissive default, and is itself decorated by Admin's operator levers — the same
        // seam shape as the three sources below, and the reason nothing has to import this module
        // to ask what a plan contains.
        $this->app->extend(
            CapabilityGate::class,
            fn (CapabilityGate $inner): CapabilityGate => new PlanCapabilityGate(
                $inner,
                $this->app->make(PlanResolver::class),
            ),
        );

        // --- The three seams ---
        $this->app->bind(CreditSource::class, PlanCreditSource::class);
        $this->app->bind(LeadQuotaSource::class, PlanLeadQuotaSource::class);
        $this->app->bind(SeatLimitSource::class, PlanSeatLimit::class);
    }

    public function boot(): void
    {
        parent::boot(); // routes + policies

        Event::listen(WebhookHandled::class, SyncSubscriptionFromStripe::class);

        // Cashier's routes carry no middleware of their own and are not ours to annotate, so the
        // webhook refusal goes on the global stack. See the middleware's own note.
        $this->app->make(Kernel::class)
            ->pushMiddleware(RefuseStripeWhenDisabled::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ReportOverageCommand::class, SyncUsageCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            // Hourly, so a team that is burning through an allowance is warned within the hour
            // rather than the next day — and so a meter row exists for the period before the
            // retention sweep prunes what it was computed from.
            $schedule->command('billing:sync-usage')->hourly()->withoutOverlapping();

            // Daily and early, before the business day: overage is summed by Stripe over the
            // period, so nothing is gained by reporting it more often, and a Stripe call per
            // discovery run would put a payment provider in the path of a lead search.
            $schedule->command('billing:report-overage')->dailyAt('04:10')->withoutOverlapping();
        });

        $this->registerGates();
        $this->startTrialOnNewTeams();
        $this->assertWebhooksAreVerifiedInProduction();
    }

    /**
     * Refuse to boot in production with Stripe configured but its webhook secret missing.
     *
     * Cashier only applies signature verification when `cashier.webhook.secret` is set, so an unset
     * secret does not make webhooks fail — it makes them **succeed without being checked**, and the
     * events this module acts on grant plans and change entitlements. A missing secret is therefore
     * not a degraded mode, it is an open endpoint that upgrades anybody who posts to it, and the
     * failure is silent in a way no test and no log line would surface.
     *
     * Only when Stripe is actually configured, so a local or CI environment with no keys at all is
     * unaffected (`phpunit.xml` deliberately clears all three).
     */
    private function assertWebhooksAreVerifiedInProduction(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        $configured = is_string(config('cashier.secret')) && config('cashier.secret') !== '';
        $verified = is_string(config('cashier.webhook.secret')) && config('cashier.webhook.secret') !== '';

        if ($configured && ! $verified) {
            throw new RuntimeException(
                'STRIPE_WEBHOOK_SECRET is not set. Stripe webhooks would be accepted without a '
                .'signature, and those events grant plans. Set it, or unset STRIPE_SECRET.'
            );
        }
    }

    /**
     * Give every new team its free trial.
     *
     * A model event rather than a line in `Auth\Actions\CreateTeamAction`, because the length of a
     * trial — and the fact that there is one at all — is a billing decision, and Auth creating a
     * team should not have to know about it. It also means a team created by anything else at all
     * (a seeder, a factory in a test, an admin tool later) starts on the same footing, instead of
     * silently landing on the free plan because it took a different path.
     *
     * Guarded on the column already being set, so re-saving a team never extends its trial, and a
     * configured length of zero switches trials off entirely.
     */
    private function startTrialOnNewTeams(): void
    {
        Team::created(static function (Team $team): void {
            if ($team->trial_ends_at !== null) {
                return;
            }

            $days = max(0, (int) config('billing.trial.days', 14));

            if ($days === 0) {
                return;
            }

            $team->forceFill([
                'trial_ends_at' => CarbonImmutable::now('UTC')->addDays($days),
            ])->save();
        });
    }

    /**
     * The two billing abilities.
     *
     * Gates rather than a policy, for the same reason Compliance uses them: the subject is the
     * **team**, and `Team` already has Auth's `TeamPolicy` — a model takes one policy, and bolting
     * this module's rules onto another module's file would hide them.
     *
     * The split is deliberate. **Viewing is every member's business**: when discovery stops
     * mid-afternoon, the person it stopped for should be able to see that the month's allowance is
     * gone rather than file a bug. **Managing is the Owners' and Admins'** — it spends money, and
     * it is the same pair who already manage members and seats, so a team does not have to learn a
     * second idea of who is in charge.
     */
    private function registerGates(): void
    {
        Gate::define('billing.view', static fn (User $user, Team $team): bool => $user->belongsToTeam($team));

        Gate::define('billing.manage', static function (User $user, Team $team): bool {
            if (! $user->belongsToTeam($team)) {
                return false;
            }

            $role = $user->roleOn($team);

            return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
        });
    }
}
