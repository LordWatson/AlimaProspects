<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Providers;

use App\Models\User;
use App\Modules\Compliance\Console\PurgeExpiredDataCommand;
use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Compliance\Models\RetentionPolicy;
use App\Modules\Compliance\Policies\LiaRecordPolicy;
use App\Modules\Compliance\Policies\RetentionPolicyPolicy;
use App\Modules\Compliance\Services\LegitimateInterestGate;
use App\Modules\Compliance\Services\MarketConsentGate;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Contracts\LeadInterestGate;
use App\Modules\ModuleServiceProvider;
use App\Modules\Outreach\Contracts\LeadRestrictionResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

final class ComplianceServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        LiaRecord::class => LiaRecordPolicy::class,
        RetentionPolicy::class => RetentionPolicyPolicy::class,
    ];

    public function boot(): void
    {
        parent::boot(); // routes + policies

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeExpiredDataCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            // Nightly, off-peak, and **after** the other modules' sweeps (Signals 03:20, insights
            // around the same hour) so the night's work moves outwards: each module prunes its own
            // derived data first, and the lead-level sweep runs last over what is left.
            $schedule->command('compliance:purge')->dailyAt('03:40')->withoutOverlapping();
        });

        $this->registerGates();

        // The export reads every table the team owns — the heaviest thing a member can ask for, and
        // the one request that produces a copy of a whole tenant's personal data. Per member rather
        // than per IP, so a shared office doesn't throttle itself.
        RateLimiter::for('compliance-export', fn (Request $request): Limit => Limit::perHour(
            max(1, (int) config('compliance.export.rate_limit', 3)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // The public unsubscribe endpoint. Keyed by IP, because there is no account behind it, and
        // generous: a recipient who clicks twice, or a mail client that retries, must never be
        // turned away from an opt-out. The cap is there because an unauthenticated endpoint that
        // writes should not be unbounded, not because the traffic is expected.
        RateLimiter::for('compliance-unsubscribe', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('outreach.unsubscribe.rate_limit', 20)),
        )->by((string) $request->ip()));
    }

    public function register(): void
    {
        // The real LIA gate, replacing Lead Sourcing's permit-everything stub. Discovery is refused
        // without an assessment in force for the team's market (docs/compliance.md §2). The seam was
        // built in Phase 7 precisely so this is a binding rather than a change to the Action.
        $this->app->bind(LeadInterestGate::class, LegitimateInterestGate::class);

        // The market-aware outreach consent gate, over Outreach's recorded-flag-only default. This
        // is what closes the hole the flag left: a company sourced from a UAE business listing
        // carries no flag, and before this a team could cold email a prospect UAE PDPL requires
        // prior consent to approach (ADR 0007).
        $this->app->bind(LeadRestrictionResolver::class, MarketConsentGate::class);
    }

    /**
     * Gates for the two team-level powers that have no model of their own.
     *
     * Gates rather than a policy because the subject is the **team**, and `Team` already has
     * `Auth\Policies\TeamPolicy` — a model takes one policy, and bolting compliance abilities onto
     * the Auth module's policy would put this module's rules in another module's file.
     *
     * Both are **Owner/Admin only**, and for the same reason: each is a power over the whole
     * tenant's personal data rather than a view of it. Exporting hands somebody a copy of every
     * prospect the team holds; a data-subject search reads everything the team has about a named
     * person and can erase it. Neither is daily work, and a Member who needs one has an Owner to
     * ask.
     */
    private function registerGates(): void
    {
        Gate::define('compliance.export', static fn (User $user, Team $team): bool => self::isAdmin($user, $team));
        Gate::define('compliance.dsar', static fn (User $user, Team $team): bool => self::isAdmin($user, $team));
    }

    private static function isAdmin(User $user, Team $team): bool
    {
        if (! $user->belongsToTeam($team)) {
            return false;
        }

        $role = $user->roleOn($team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
