<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Admin\Enums\PlatformMetric;
use App\Modules\Admin\Enums\PlatformProvider;
use App\Modules\AiPlatform\Enums\AiRequestStatus;
use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\BillingMode;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Models\PlanGrant;
use App\Modules\Billing\Models\UsageMeter;
use App\Modules\Billing\Services\PlanRegistry;
use App\Modules\Compliance\Actions\PurgeExpiredDataAction;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Outreach\Enums\OutreachMessageStatus;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;

/**
 * Computes one UTC day of `platform_daily_metrics` from the ledgers (Phase 19c).
 *
 * **A record over the ledgers, not a counter** (ADR 0012's rule for `usage_meters`, applied again).
 * Nothing in the application increments a figure; this class derives every one of them and
 * {@see PlatformMetricStore::replaceDay()} writes each metric's day atomically. Running it twice
 * on the same day leaves exactly the same numbers, which is what makes a backfill safe and what
 * makes a crashed worker a gap rather than a corruption.
 *
 * ## Why it runs before the retention sweep
 *
 * {@see PurgeExpiredDataAction} prunes the very rows these figures are computed from. Once a
 * period has been swept, it cannot be recomputed — the same constraint `billing:sync-usage` runs
 * hourly for, and the reason `platform:roll-up` is scheduled at 02:40, ahead of every module's
 * nightly prune (Signals 03:20, Compliance 03:40, insights 03:50). `PlatformRollupTest` asserts
 * the ordering against the real schedule rather than trusting the comment.
 *
 * ## Three shapes of figure, and what a backfill can honestly do with each
 *
 * 1. **Event-shaped.** Derived from a timestamp on a row: signups, AI cost, prospects sourced,
 *    messages sent. Perfectly recomputable for any day whose rows survive, so a backfill rewrites
 *    them.
 * 2. **Reconstructable state.** MRR and paying tenants. The plan in force on a past day is
 *    rebuilt from the **grant and subscription windows** rather than asked of
 *    `PlanResolver` — which answers for *now*, and would stamp today's plan onto last month.
 *    The one thing that cannot be rebuilt is Stripe's *status* history, so a subscription's window
 *    comes from its row and its status is read as it stands; that is right for MRR (a subscription
 *    that was briefly past due was still revenue) and is exactly why dunning is in the third group.
 * 3. **Point-in-time state.** Dunning, seats, allowance consumption and reported overage. Nothing
 *    historises them: `usage_meters.used` is the current figure for an open period and
 *    `subscriptions.stripe_status` is today's status. A backfill therefore **skips** them — it
 *    neither writes nor deletes — because inventing a state nobody recorded is worse than leaving
 *    a gap. {@see self::handle()} reports which group it wrote.
 */
final class PlatformRollup
{
    /**
     * The activity types that mean a **member did something** to a prospect.
     *
     * The activation rule, written once. Discovery, enrichment and scoring are things Alima did;
     * a team that has only watched them happen has not activated, and counting them would make
     * the activation figure a restatement of the signup figure.
     *
     * @return list<LeadActivityType>
     */
    public static function workedTypes(): array
    {
        return [
            LeadActivityType::StageChanged,
            LeadActivityType::NoteAdded,
            LeadActivityType::TaskCreated,
            LeadActivityType::TaskCompleted,
            LeadActivityType::Assigned,
            LeadActivityType::TagsChanged,
            LeadActivityType::Contacted,
        ];
    }

    /** The Stripe statuses that mean a payment has failed and the subscription is stuck. */
    public const array DUNNING_STATUSES = ['past_due', 'unpaid', 'incomplete'];

    /** Allowance-consumption bands, as `[label, lowerBoundPercent]` — see the metric's definition. */
    private const array BANDS = [
        ['0-25', 0],
        ['25-50', 25],
        ['50-75', 50],
        ['75-100', 75],
        ['100+', 100],
    ];

    public function __construct(
        private readonly PlatformMetricStore $store,
        private readonly PlanRegistry $plans,
    ) {}

    /**
     * Roll one day up.
     *
     * `$pointInTime` says whether the caller believes today's state can honestly be attributed to
     * `$date` — true for the nightly run over yesterday, false for a backfill. The command decides;
     * this class does not guess.
     *
     * @return array<string, int> metric value => rows written, for the command's output
     */
    public function handle(CarbonImmutable $date, bool $pointInTime = true): array
    {
        $day = $date->utc()->startOfDay();

        $written = [];

        $written += $this->rollUpGrowth($day);
        $written += $this->rollUpRevenue($day, $pointInTime);
        $written += $this->rollUpCost($day);
        $written += $this->rollUpOutreach($day);
        $written += $this->rollUpSignals($day, $pointInTime);

        if ($pointInTime) {
            $written += $this->rollUpPointInTimeState($day);
            $this->store->replaceDay($day, PlatformMetric::RollupStateDay, ['' => 1]);
            $written[PlatformMetric::RollupStateDay->value] = 1;
        }

        // **Last**, and only once everything above has been written. The marker is what tells a
        // reader that an absent snapshot means zero rather than not-yet-computed, so writing it
        // before the figures would make a half-finished day look complete.
        $this->store->replaceDay($day, PlatformMetric::RollupDay, ['' => 1]);
        $written[PlatformMetric::RollupDay->value] = 1;

        return $written;
    }

    /**
     * Recompute the six cohort-shaped funnel metrics for one **signup cohort** day.
     *
     * Separate from {@see self::handle()} because its date means something different: the row is
     * dated by the day the cohort signed up, and its value changes every time one of those teams
     * takes another step. The command therefore walks a trailing window of cohort days every
     * night (`admin.reporting.funnel_cohort_days`) rather than writing each day once.
     *
     * @return array<string, int>
     */
    public function rollUpFunnel(CarbonImmutable $date): array
    {
        $day = $date->utc()->startOfDay();
        [$from, $to] = [$day, $day->addDay()];

        /** @var list<int> $cohort */
        $cohort = Team::query()
            ->whereBetween('created_at', [$from, $to->subSecond()])
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        // A list of `[metric, value]` pairs rather than a map keyed by the enum: PHP array keys
        // are only ever `int|string`, so an enum-keyed array silently coerces and PHPStan is right
        // to refuse it.
        $steps = [
            [PlatformMetric::FunnelSignedUp, count($cohort)],
            [PlatformMetric::FunnelProfile, $this->distinctTeams('business_profiles', $cohort)],
            [PlatformMetric::FunnelIcp, $this->distinctTeams('icps', $cohort)],
            [PlatformMetric::FunnelDiscovery, $this->distinctTeams('discovery_runs', $cohort)],
            [PlatformMetric::FunnelLeadWorked, $this->workedTeams($cohort)],
            [PlatformMetric::FunnelOutreach, $this->distinctTeams('contact_attempts', $cohort)],
        ];

        $written = [];

        foreach ($steps as [$metric, $value]) {
            // An empty cohort still writes a zero row for the denominator, so "nobody signed up"
            // reads differently from "we have not rolled this day up".
            $this->store->replaceDay($day, $metric, ['' => $value]);
            $written[$metric->value] = 1;
        }

        return $written;
    }

    /**
     * How many of the cohort have taken a **member-driven** action on a prospect.
     *
     * Its own method rather than an inline expression, because the activation rule lives in
     * {@see self::workedTypes()} and both the funnel step and the activation count have to ask it
     * the same way — the moment they diverge, the funnel's last-but-one step stops agreeing with
     * the activation figure beside it.
     *
     * @param  list<int>  $cohort
     */
    private function workedTeams(array $cohort): int
    {
        if ($cohort === []) {
            return 0;
        }

        return (int) LeadActivity::query()
            ->withoutGlobalScopes()
            ->whereIn('team_id', $cohort)
            ->whereIn('type', array_map(
                static fn (LeadActivityType $type): string => $type->value,
                self::workedTypes(),
            ))
            ->distinct()
            ->count('team_id');
    }

    /* ------------------------------------------------------------------ Growth */

    /**
     * @return array<string, int>
     */
    private function rollUpGrowth(CarbonImmutable $day): array
    {
        [$from, $to] = $this->bounds($day);

        $signups = Team::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('market, COUNT(*) as aggregate')
            ->groupBy('market')
            ->pluck('aggregate', 'market')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $trials = Team::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('trial_ends_at')
            ->count();

        $this->store->replaceDay($day, PlatformMetric::Signups, $signups);
        $this->store->replaceDay($day, PlatformMetric::TrialStarts, ['' => $trials]);

        return [
            PlatformMetric::Signups->value => count($signups),
            PlatformMetric::TrialStarts->value => 1,
        ]
            + $this->rollUpActivations($day, $from, $to)
            + $this->rollUpConversions($day, $from, $to);
    }

    /**
     * @return array<string, int>
     */
    private function rollUpActivations(CarbonImmutable $day, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // `MIN(occurred_at)` per tenant, then the ones whose first-ever member action lands inside
        // the day. A `HAVING` over the grouped minimum rather than two queries, because the
        // alternative — "activities in the window, excluding teams with an earlier one" — is the
        // same thing written twice and the two drift.
        $firstActions = LeadActivity::query()
            ->withoutGlobalScopes()
            ->whereIn('type', array_map(
                static fn (LeadActivityType $type): string => $type->value,
                self::workedTypes(),
            ))
            ->selectRaw('team_id')
            ->groupBy('team_id')
            ->havingRaw('MIN(occurred_at) >= ?', [$from])
            ->havingRaw('MIN(occurred_at) <= ?', [$to]);

        // Counted as a subquery rather than by hydrating the grouped rows. The figure is "how many
        // tenants", and Eloquent's own `count()` on a `GROUP BY ... HAVING` query counts the first
        // group's rows instead of the groups — which reads as 1 on a day when twelve tenants
        // activated.
        $activations = (int) DB::query()->fromSub($firstActions->toBase(), 'first_actions')->count();

        $this->store->replaceDay($day, PlatformMetric::Activations, ['' => $activations]);

        return [PlatformMetric::Activations->value => 1];
    }

    /**
     * Trial conversions and the day they land on.
     *
     * "First paid subscription" is `MIN(created_at)` over the tenant's subscription rows, so a
     * customer who cancelled and resubscribed converts once — on the first occasion. The second
     * occasion is a reactivation, and the MRR movement figures are where it shows up.
     *
     * @return array<string, int>
     */
    private function rollUpConversions(CarbonImmutable $day, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Cashier derives this column from the billable model's foreign key, exactly as
        // `PlanResolver` does. It is read off the model for `groupBy`/`pluck` and written as a
        // literal in the `selectRaw`, which only accepts one.
        $teamKey = (new Team)->getForeignKey();

        /** @var array<int, string> $firstSubscriptions */
        $firstSubscriptions = Subscription::query()
            ->selectRaw('team_id, MIN(created_at) as first_at')
            ->groupBy($teamKey)
            ->havingRaw('MIN(created_at) >= ?', [$from])
            ->havingRaw('MIN(created_at) <= ?', [$to])
            ->pluck('first_at', $teamKey)
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();

        $signupDates = $firstSubscriptions === [] ? [] : Team::query()
            ->whereIn('id', array_keys($firstSubscriptions))
            ->pluck('created_at', 'id')
            ->all();

        $byDay = [];

        foreach ($firstSubscriptions as $teamId => $convertedAt) {
            $signedUp = $signupDates[$teamId] ?? null;

            if ($signedUp === null) {
                continue;
            }

            $offset = max(0, CarbonImmutable::parse($signedUp)->utc()->startOfDay()
                ->diffInDays(CarbonImmutable::parse($convertedAt)->utc()->startOfDay()));

            $key = 'd'.$offset;
            $byDay[$key] = ($byDay[$key] ?? 0) + 1;
        }

        $this->store->replaceDay($day, PlatformMetric::TrialConversions, [
            '' => count($firstSubscriptions),
        ]);
        $this->store->replaceDay($day, PlatformMetric::ConversionDay, $byDay);

        return [
            PlatformMetric::TrialConversions->value => 1,
            PlatformMetric::ConversionDay->value => count($byDay),
        ];
    }

    /* ----------------------------------------------------------------- Revenue */

    /**
     * MRR, per-tenant MRR, paying tenants and the five movements.
     *
     * The plan in force on the day is reconstructed here rather than resolved — see the class
     * note, group 2. The order of precedence matches `PlanResolver`'s exactly, because two answers
     * to "what plan is this team on" is how a margin report and a billing screen come to disagree:
     * **grant, then subscription, then trial, then the default plan.**
     *
     * @return array<string, int>
     */
    private function rollUpRevenue(CarbonImmutable $day, bool $pointInTime): array
    {
        $endOfDay = $day->addDay()->subSecond();

        /** @var array<int, array{plan: string, amount: int, paying: bool}> $today */
        $today = [];

        Team::query()
            ->where('created_at', '<=', $endOfDay)
            ->orderBy('id')
            ->chunkById(500, function ($teams) use (&$today, $endOfDay): void {
                /** @var Collection<int, Team> $teams */
                $ids = $teams->modelKeys();
                $grants = $this->grantsInForce($ids, $endOfDay);
                $subscriptions = $this->subscriptionsInForce($ids, $endOfDay);

                foreach ($teams as $team) {
                    $id = (int) $team->getKey();
                    $today[$id] = $this->positionFor($team, $grants[$id] ?? null, $subscriptions[$id] ?? null, $endOfDay);
                }
            });

        $teamMrr = [];
        $teamPaying = [];
        $mrrByPlan = [];
        $payingByPlan = [];

        foreach ($today as $id => $position) {
            if ($position['amount'] > 0) {
                $teamMrr[$this->teamPlanDimension($id, $position['plan'])] = $position['amount'];
                $mrrByPlan[$position['plan']] = ($mrrByPlan[$position['plan']] ?? 0) + $position['amount'];
            }

            if ($position['paying']) {
                $payingByPlan[$position['plan']] = ($payingByPlan[$position['plan']] ?? 0) + 1;
                // Irrespective of price, which is the point: an Enterprise or invoiced tenant pays
                // us an amount the system does not know, and retention is about customers.
                $teamPaying[PlatformMetric::teamDimension($id)] = 1;
            }
        }

        $this->store->replaceDay($day, PlatformMetric::TeamMrr, $teamMrr);
        $this->store->replaceDay($day, PlatformMetric::TeamPaying, $teamPaying);
        $this->store->replaceDay($day, PlatformMetric::Mrr, $mrrByPlan);
        $this->store->replaceDay($day, PlatformMetric::PayingTeams, $payingByPlan);

        $written = [
            PlatformMetric::TeamMrr->value => count($teamMrr),
            PlatformMetric::TeamPaying->value => count($teamPaying),
            PlatformMetric::Mrr->value => count($mrrByPlan),
            PlatformMetric::PayingTeams->value => count($payingByPlan),
        ];

        $written += $this->rollUpMovement($day, $today);
        $written += $this->rollUpOverageRevenue($day, $pointInTime);

        return $written;
    }

    /**
     * The five MRR movements, by comparing today's per-tenant MRR with the **stored** row from the
     * last day we recorded.
     *
     * Read from the rollup rather than recomputed, which is what makes the figures add up: if that
     * row says a tenant was on £129 then today's movement is measured against it, whatever a
     * re-reconstruction of the day would now say. A backfill walked forwards through a window
     * therefore produces a consistent series; walked backwards it would produce one day of
     * nonsense, which is why the command only ever walks forwards.
     *
     * @param  array<int, array{plan: string, amount: int, paying: bool}>  $today
     * @return array<string, int>
     */
    private function rollUpMovement(CarbonImmutable $day, array $today): array
    {
        // **The baseline is the last day we actually recorded, not "yesterday".**
        //
        // This is the difference between a figure and a fiction. The scheduler only ever rolls up
        // yesterday, so one missed night — an outage, a failed day, a first run with no backfill —
        // leaves no row for it; measured against nothing, every paying tenant looks like it
        // appeared from nowhere and the whole platform's revenue is booked as new or reactivated
        // in a single day. Compared against the last day we know about, a gap produces the correct
        // movement *across* the gap instead.
        $baseline = $this->store->latestDayBefore($day, PlatformMetric::TeamMrr);

        if ($baseline === null) {
            // Nothing to compare against at all — the very first run. Movement is left **unwritten**
            // rather than reported as an entire platform of new business, which is a number nobody
            // would believe and nobody could correct. `--days` is how a history gets its movement.
            $written = [];

            foreach (PlatformMetric::movement() as $metric) {
                $this->store->replaceDay($day, $metric, []);
                $written[$metric->value] = 0;
            }

            return $written;
        }

        $baselineDay = CarbonImmutable::parse($baseline, 'UTC');
        $previous = [];

        foreach ($this->store->dimensionsOn($baselineDay, PlatformMetric::TeamMrr) as $dimension => $amount) {
            [$teamId, $plan] = $this->splitTeamPlanDimension($dimension);

            if ($teamId !== null) {
                $previous[$teamId] = ['plan' => $plan, 'amount' => $amount];
            }
        }

        $movements = [
            PlatformMetric::MrrNew->value => [],
            PlatformMetric::MrrExpansion->value => [],
            PlatformMetric::MrrContraction->value => [],
            PlatformMetric::MrrChurn->value => [],
            PlatformMetric::MrrReactivation->value => [],
        ];

        $add = static function (PlatformMetric $metric, string $plan, int $amount) use (&$movements): void {
            if ($amount === 0) {
                return;
            }

            $movements[$metric->value][$plan] = ($movements[$metric->value][$plan] ?? 0) + $amount;
        };

        foreach ($today as $id => $position) {
            $now = $position['amount'];
            $was = $previous[$id]['amount'] ?? 0;

            if ($now === $was) {
                continue;
            }

            if ($was === 0) {
                // New or returning. The question is about the whole history, not the window —
                // `everPositiveBefore` is why a customer who churned last year is not counted as
                // a new one today.
                $returning = $this->store->everPositiveBeforeForTeam($baselineDay, PlatformMetric::TeamMrr, $id);

                $add($returning ? PlatformMetric::MrrReactivation : PlatformMetric::MrrNew, $position['plan'], $now);

                continue;
            }

            if ($now === 0) {
                // Churn, against the plan they **left** — which is why the per-tenant dimension
                // carries the plan at all.
                $add(PlatformMetric::MrrChurn, $previous[$id]['plan'] ?? $position['plan'], -$was);

                continue;
            }

            $add(
                $now > $was ? PlatformMetric::MrrExpansion : PlatformMetric::MrrContraction,
                $position['plan'],
                $now - $was,
            );
        }

        // A tenant that has been deleted outright since the baseline day is churn too, and would
        // otherwise vanish from the figures without ever appearing in the movement that explains
        // where the revenue went.
        foreach ($previous as $id => $position) {
            if (! array_key_exists($id, $today) && $position['amount'] > 0) {
                $add(PlatformMetric::MrrChurn, $position['plan'], -$position['amount']);
            }
        }

        $written = [];

        foreach ($movements as $metric => $byPlan) {
            $enum = PlatformMetric::from($metric);
            $this->store->replaceDay($day, $enum, $byPlan);
            $written[$metric] = count($byPlan);
        }

        return $written;
    }

    /**
     * Overage **already reported to Stripe** for the billing periods open on the day, priced at the
     * configured per-unit amount.
     *
     * Point-in-time: `usage_meters.reported` is a running total for an open period, so a backfill
     * cannot say what it stood at last Tuesday.
     *
     * @return array<string, int>
     */
    private function rollUpOverageRevenue(CarbonImmutable $day, bool $pointInTime): array
    {
        if (! $pointInTime) {
            return [];
        }

        $period = BillingPeriod::containing($day);
        $revenue = [];

        foreach (BillingMetric::cases() as $metric) {
            $units = (int) UsageMeter::query()
                ->withoutGlobalScopes()
                ->where('metric', $metric->value)
                ->where('period_start', $period->start)
                ->sum('reported');

            $amount = (int) config('billing.overage.'.$metric->value.'.amount', 0);

            if ($units > 0 && $amount > 0) {
                $revenue[$metric->value] = $units * $amount;
            }
        }

        $this->store->replaceDay($day, PlatformMetric::OverageRevenue, $revenue);

        return [PlatformMetric::OverageRevenue->value => count($revenue)];
    }

    /* ------------------------------------------------ Point-in-time state */

    /**
     * Dunning, seats and allowance consumption — the figures nothing historises.
     *
     * @return array<string, int>
     */
    private function rollUpPointInTimeState(CarbonImmutable $day): array
    {
        $dunning = Subscription::query()
            ->whereIn('stripe_status', self::DUNNING_STATUSES)
            ->selectRaw('stripe_status, COUNT(*) as aggregate')
            ->groupBy('stripe_status')
            ->pluck('aggregate', 'stripe_status')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $seatsUsed = (int) DB::table('team_user')->count();

        $this->store->replaceDay($day, PlatformMetric::DunningSubscriptions, $dunning);
        $this->store->replaceDay($day, PlatformMetric::SeatsUsed, ['' => $seatsUsed]);
        $this->store->replaceDay($day, PlatformMetric::SeatsEntitled, ['' => $this->seatsEntitled()]);

        return [
            PlatformMetric::DunningSubscriptions->value => count($dunning),
            PlatformMetric::SeatsUsed->value => 1,
            PlatformMetric::SeatsEntitled->value => 1,
        ] + $this->rollUpAllowanceConsumption($day);
    }

    /**
     * Seats the plans in force entitle tenants to.
     *
     * A tenant on an **unlimited** plan contributes the seats it is using, not nothing and not
     * infinity: the figure exists to be divided into `SeatsUsed`, and either alternative would make
     * that ratio say something about Enterprise rather than about utilisation.
     */
    private function seatsEntitled(): int
    {
        $now = CarbonImmutable::now('UTC');
        $total = 0;

        /** @var array<int, int> $memberships */
        $memberships = DB::table('team_user')
            ->selectRaw('team_id, COUNT(*) as aggregate')
            ->groupBy('team_id')
            ->pluck('aggregate', 'team_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        Team::query()->orderBy('id')->chunkById(500, function ($teams) use (&$total, $memberships, $now): void {
            /** @var Collection<int, Team> $teams */
            $ids = $teams->modelKeys();
            $grants = $this->grantsInForce($ids, $now);
            $subscriptions = $this->subscriptionsInForce($ids, $now);

            foreach ($teams as $team) {
                $id = (int) $team->getKey();
                $position = $this->positionFor($team, $grants[$id] ?? null, $subscriptions[$id] ?? null, $now);
                $plan = $this->plans->fromKey($position['plan']);

                $grant = $grants[$id] ?? null;
                // A grant's own seat override beats the plan's, which is the whole point of the
                // override columns; `null` on either means "use the plan's", and an unlimited plan
                // falls back to the tenant's membership count — see the method note.
                $seats = ($grant instanceof PlanGrant ? $grant->seats : null) ?? $plan->seats;
                $total += $seats ?? ($memberships[$id] ?? 0);
            }
        });

        return $total;
    }

    /**
     * How close tenants are to their allowances, as a count at the ceiling and a five-band
     * distribution.
     *
     * **`included = 0` is read as unlimited**, which is not obvious and is a real property of the
     * data: `UsageLedger::sync()` writes `allowanceFor($metric) ?? 0`, so an Enterprise tenant's
     * meter stores a zero where the plan means "no limit". A tenant that cannot hit a ceiling is
     * excluded from both figures rather than counted as permanently over it.
     *
     * @return array<string, int>
     */
    private function rollUpAllowanceConsumption(CarbonImmutable $day): array
    {
        $period = BillingPeriod::containing($day);

        $ceiling = [];
        $bands = [];

        foreach (BillingMetric::cases() as $metric) {
            $meters = UsageMeter::query()
                ->withoutGlobalScopes()
                ->where('metric', $metric->value)
                ->where('period_start', $period->start)
                ->where('included', '>', 0)
                ->get(['used', 'included']);

            $atCeiling = 0;

            foreach ($meters as $meter) {
                $percent = (int) floor($meter->used * 100 / max(1, $meter->included));

                if ($percent >= 100) {
                    $atCeiling++;
                }

                $band = self::BANDS[0][0];

                foreach (self::BANDS as [$label, $lower]) {
                    if ($percent >= $lower) {
                        $band = $label;
                    }
                }

                $key = $metric->value.':'.$band;
                $bands[$key] = ($bands[$key] ?? 0) + 1;
            }

            $ceiling[$metric->value] = $atCeiling;
        }

        $this->store->replaceDay($day, PlatformMetric::AllowanceCeiling, $ceiling);
        $this->store->replaceDay($day, PlatformMetric::AllowanceBand, $bands);

        return [
            PlatformMetric::AllowanceCeiling->value => count($ceiling),
            PlatformMetric::AllowanceBand->value => count($bands),
        ];
    }

    /* -------------------------------------------------------------------- Cost */

    /**
     * @return array<string, int>
     */
    private function rollUpCost(CarbonImmutable $day): array
    {
        [$from, $to] = $this->bounds($day);

        $ai = AiRequest::query()->withoutGlobalScopes()->whereBetween('created_at', [$from, $to]);

        $byModel = (clone $ai)
            ->selectRaw('model, SUM(cost_micros) as aggregate')
            ->groupBy('model')
            ->pluck('aggregate', 'model')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $byWork = (clone $ai)
            ->selectRaw('prompt_key, SUM(cost_micros) as aggregate')
            ->groupBy('prompt_key')
            ->pluck('aggregate', 'prompt_key')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $byStatus = (clone $ai)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $latency = (int) (clone $ai)->sum('latency_ms');

        $this->store->replaceDay($day, PlatformMetric::AiCostMicros, $byModel);
        $this->store->replaceDay($day, PlatformMetric::AiCostMicrosByWork, $byWork);
        $this->store->replaceDay($day, PlatformMetric::AiRequests, $byStatus);
        $this->store->replaceDay($day, PlatformMetric::AiLatencyMsTotal, ['' => $latency]);

        $leads = Lead::query()
            ->withoutGlobalScopes()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('source, COUNT(*) as aggregate')
            ->groupBy('source')
            ->pluck('aggregate', 'source')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $this->store->replaceDay($day, PlatformMetric::LeadsCreated, $leads);

        return [
            PlatformMetric::AiCostMicros->value => count($byModel),
            PlatformMetric::AiCostMicrosByWork->value => count($byWork),
            PlatformMetric::AiRequests->value => count($byStatus),
            PlatformMetric::AiLatencyMsTotal->value => 1,
            PlatformMetric::LeadsCreated->value => count($leads),
        ]
            + $this->rollUpProviders($day, $from, $to)
            + $this->rollUpTeamCost($day, $from, $to);
    }

    /**
     * Calls, failures and cost per external provider.
     *
     * The three ledgers are folded onto {@see PlatformProvider}'s vocabulary here: every row in
     * `ai_requests` is a Claude call whichever model served it, `discovery_runs.source` already
     * matches the three lead providers, and `signal_runs` is all Serper News. Resend, Stripe and
     * push have no ledger of their own in this product, so they get no rows — and the health screen
     * says so rather than drawing an empty chart.
     *
     * @return array<string, int>
     */
    private function rollUpProviders(CarbonImmutable $day, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Seeded with a zero per metered provider **before** anything is counted, so a provider
        // that made no calls reads as idle rather than as missing — and so a dimension that was
        // busy yesterday and quiet today is overwritten with a zero instead of being left behind
        // on the screen at yesterday's figure.
        $calls = [];
        $failures = [];
        $cost = [];

        foreach (PlatformProvider::cases() as $provider) {
            if ($provider->isMetered()) {
                $calls[$provider->metricDimension()] = 0;
                $failures[$provider->metricDimension()] = 0;
                $cost[$provider->metricDimension()] = 0;
            }
        }

        $ai = AiRequest::query()->withoutGlobalScopes()->whereBetween('created_at', [$from, $to]);
        $claude = PlatformProvider::Claude->metricDimension();

        $calls[$claude] = (clone $ai)->count();
        $failures[$claude] = (clone $ai)->where('status', AiRequestStatus::Failed->value)->count();
        $cost[$claude] = (int) (clone $ai)->sum('cost_micros');

        $runs = DiscoveryRun::query()->withoutGlobalScopes()->whereBetween('created_at', [$from, $to]);

        foreach ($this->groupedCounts($runs, 'source') as $source => $count) {
            $calls[$source] = $count;
        }

        foreach ($this->groupedCounts(
            (clone $runs)->where('status', DiscoveryRunStatus::Failed->value),
            'source',
        ) as $source => $count) {
            $failures[$source] = $count;
        }

        foreach ((clone $runs)
            ->selectRaw('source, SUM(cost_micros) as aggregate')
            ->groupBy('source')
            ->pluck('aggregate', 'source') as $source => $micros) {
            $cost[(string) $source] = (int) $micros;
        }

        $signals = SignalRun::query()->withoutGlobalScopes()->whereBetween('created_at', [$from, $to]);
        $news = PlatformProvider::SerperNews->metricDimension();

        // **A run refused for a spent plan allowance is not a provider failure** (S16 review). S16
        // records that refusal as a `Failed` run on purpose — it is a cost event and the watch card
        // is where a team should see it — but counting it here would make every team on a ceiling
        // raise the platform's Serper failure rate once an hour per watch, and the alert an
        // operator would then act on is about a provider that is perfectly healthy.
        //
        // Excluded by `error_code`, not by the message: the message is translated prose. Through
        // `providerFailures()` rather than a `whereNotIn` here, because a null `error_code` does not
        // match a `NOT IN` and almost every real failure has one — see the scope.
        $providerFailures = (clone $signals)->providerFailures();

        $calls[$news] = (clone $signals)->count();
        $failures[$news] = $providerFailures->count();
        $cost[$news] = (int) (clone $signals)->sum('cost_micros');

        // Zeros are kept for the providers that have a ledger, so a provider that made no calls
        // reads as idle rather than as missing. A source that is not a provider at all — `import`,
        // `manual`, `signal` — is dropped: counting a CSV import as a provider call would make the
        // failure rate meaningless.
        $providers = PlatformProvider::values();
        $keep = static fn (array $rows): array => array_filter(
            $rows,
            static fn (int $value, string $key): bool => in_array($key, $providers, true),
            ARRAY_FILTER_USE_BOTH,
        );

        $this->store->replaceDay($day, PlatformMetric::ProviderCalls, $keep($calls));
        $this->store->replaceDay($day, PlatformMetric::ProviderFailures, $keep($failures));
        $this->store->replaceDay($day, PlatformMetric::ProviderCostMicros, $keep($cost));

        return [
            PlatformMetric::ProviderCalls->value => count($keep($calls)),
            PlatformMetric::ProviderFailures->value => count($keep($failures)),
            PlatformMetric::ProviderCostMicros->value => count($keep($cost)),
        ];
    }

    /**
     * Provider cost attributed to one tenant, split by the currency the provider billed in.
     *
     * **Spend with no tenant behind it goes to `unattributed`, never spread.** `ai_requests.team_id`
     * is nullable — a platform job, a backfill, a call made outside any tenant's context — and
     * dividing that cost across customers would flatter every margin equally and be impossible to
     * notice. The margin screen shows the unattributed line next to the per-tenant ones.
     *
     * `discovery_runs` and `signal_runs` have **no currency column**, so their currency comes from
     * `admin.reporting.data_cost_currency`, which that config key explains. A currency column on
     * both tables is the right fix and is recorded as a deviation in the module doc.
     *
     * @return array<string, int>
     */
    private function rollUpTeamCost(CarbonImmutable $day, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cost = [];

        $rows = AiRequest::query()
            ->withoutGlobalScopes()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('team_id, currency, SUM(cost_micros) as aggregate')
            ->groupBy('team_id', 'currency')
            ->get();

        foreach ($rows as $row) {
            $teamId = $row->getAttributes()['team_id'] ?? null;
            $owner = $teamId === null
                ? 'unattributed'
                : PlatformMetric::teamDimension((int) $teamId);

            $key = PlatformMoney::costDimension($owner, (string) $row->getAttributes()['currency']);
            $cost[$key] = ($cost[$key] ?? 0) + (int) $row->getAttributes()['aggregate'];
        }

        $dataCurrency = (string) config('admin.reporting.data_cost_currency', 'usd');

        foreach ([DiscoveryRun::class, SignalRun::class] as $model) {
            /** @var Builder<Model> $query */
            $query = $model::query()->withoutGlobalScopes();

            $grouped = $query
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('team_id, SUM(cost_micros) as aggregate')
                ->groupBy('team_id')
                ->pluck('aggregate', 'team_id');

            foreach ($grouped as $teamId => $micros) {
                // No `unattributed` branch here, unlike the AI ledger above: `discovery_runs` and
                // `signal_runs` both have a non-nullable `team_id`, so every row belongs to a
                // tenant by construction.
                $owner = PlatformMetric::teamDimension((int) $teamId);

                $key = PlatformMoney::costDimension($owner, $dataCurrency);
                $cost[$key] = ($cost[$key] ?? 0) + (int) $micros;
            }
        }

        $cost = array_filter($cost, static fn (int $micros): bool => $micros > 0);

        $this->store->replaceDay($day, PlatformMetric::TeamCostMicros, $cost);

        return [PlatformMetric::TeamCostMicros->value => count($cost)];
    }

    /* ---------------------------------------------------------------- Outreach */

    /**
     * @return array<string, int>
     */
    private function rollUpOutreach(CarbonImmutable $day): array
    {
        [$from, $to] = $this->bounds($day);

        $sent = OutreachMessage::query()
            ->withoutGlobalScopes()
            ->where('status', OutreachMessageStatus::Sent->value)
            ->whereBetween('sent_at', [$from, $to])
            ->selectRaw('channel, COUNT(*) as aggregate')
            ->groupBy('channel')
            ->pluck('aggregate', 'channel')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $attempts = ContactAttempt::query()
            ->withoutGlobalScopes()
            ->whereBetween('occurred_at', [$from, $to]);

        $byChannel = (clone $attempts)
            ->selectRaw('channel, COUNT(*) as aggregate')
            ->groupBy('channel')
            ->pluck('aggregate', 'channel')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $outcomes = [];

        foreach ((clone $attempts)
            ->selectRaw('channel, outcome, COUNT(*) as aggregate')
            ->groupBy('channel', 'outcome')
            ->get() as $row) {
            $attributes = $row->getAttributes();
            $outcomes[$attributes['channel'].':'.$attributes['outcome']] = (int) $attributes['aggregate'];
        }

        $this->store->replaceDay($day, PlatformMetric::OutreachSent, $sent);
        $this->store->replaceDay($day, PlatformMetric::OutreachAttempts, $byChannel);
        $this->store->replaceDay($day, PlatformMetric::OutreachOutcomes, $outcomes);

        return [
            PlatformMetric::OutreachSent->value => count($sent),
            PlatformMetric::OutreachAttempts->value => count($byChannel),
            PlatformMetric::OutreachOutcomes->value => count($outcomes),
        ];
    }

    /**
     * What the platform's signal library is actually being used for (S15 task 6).
     *
     * **Platform reporting is not tenant reporting with the scope removed** (ADR 0016), and these
     * four figures are the clearest example of it in the product: no tenant has ever wanted to know
     * which library preset is cloned most, and the business cannot decide which packs to invest in
     * without it. So the questions are the panel's own, and the figures come out of the ledgers the
     * same way every other metric here does.
     *
     * The **dimension is the preset key, or `own`**. A type cloned from `uk_acquisition` is
     * attributed to that preset for ever, including after a team has renamed and retuned it,
     * because the question is "was this preset worth offering?" and the answer is yes if somebody
     * started from it. A type a team built from scratch is `own`, which is itself a figure worth
     * watching: a library nobody starts from is a library that is missing something.
     *
     * Two of them are **point-in-time** and two are of the day. Types in use is a count of rows
     * that exist now — there is no history of when a type was switched on — so it is written for
     * the day the rollup ran and a reconstructed day deliberately gets nothing, which is the same
     * treatment {@see self::rollUpPointInTimeState()} gives seats. The signal counts are of the
     * day and can be recomputed for any day in the window.
     *
     * @return array<string, int>
     */
    private function rollUpSignals(CarbonImmutable $day, bool $pointInTime): array
    {
        [$from, $to] = $this->bounds($day);

        // The preset each definition descends from, so a signal's `signal_definition_id` can be
        // attributed without joining three tables in every query below. Read once; the number of
        // definitions across the platform is small by construction (`max_definitions_per_team`).
        $presetOf = $this->presetKeysByDefinition();

        $found = [];
        $outcomes = [];

        $rows = Signal::query()
            ->withoutGlobalScopes()
            ->representatives()
            ->whereBetween('signals.created_at', [$from, $to])
            ->groupBy('signals.signal_definition_id')
            ->selectRaw(
                'signals.signal_definition_id as definition_id, '
                .'count(*) as found, '
                .'sum(case when signals.status != ? then 1 else 0 end) as kept, '
                .'sum(case when signals.status = ? then 1 else 0 end) as converted',
                [SignalStatus::Dismissed->value, SignalStatus::Converted->value],
            )
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            // A signal whose definition has been deleted has a null id (`nullOnDelete`, ADR 0018
            // §4) and is attributed to `unattributed` rather than dropped — the same treatment
            // `TeamCostMicros` gives spend no tenant owns. Losing it would make the platform's
            // total disagree with the tenants' and nobody would be able to find the difference.
            $dimension = $row->definition_id === null
                ? 'unattributed'
                : ($presetOf[(int) $row->definition_id] ?? 'own');

            $found[$dimension] = ($found[$dimension] ?? 0) + (int) $row->found;
            $outcomes[$dimension.':kept'] = ($outcomes[$dimension.':kept'] ?? 0) + (int) $row->kept;
            $outcomes[$dimension.':converted'] = ($outcomes[$dimension.':converted'] ?? 0) + (int) $row->converted;
        }

        $bySource = $this->groupedCounts(
            Signal::query()
                ->withoutGlobalScopes()
                ->representatives()
                ->whereBetween('signals.created_at', [$from, $to]),
            'signals.source_kind',
        );

        $this->store->replaceDay($day, PlatformMetric::SignalsFound, $found);
        $this->store->replaceDay($day, PlatformMetric::SignalOutcomes, $outcomes);
        $this->store->replaceDay($day, PlatformMetric::SignalsBySource, $bySource);

        $written = [
            PlatformMetric::SignalsFound->value => count($found),
            PlatformMetric::SignalOutcomes->value => count($outcomes),
            PlatformMetric::SignalsBySource->value => count($bySource),
        ];

        // Point-in-time only. There is no record of when a type was switched on, so writing this
        // for a reconstructed day would be yesterday's shape stamped with last month's date.
        if ($pointInTime) {
            $inUse = [];

            foreach ($this->activeDefinitionPresets() as $dimension => $count) {
                $inUse[$dimension] = $count;
            }

            $this->store->replaceDay($day, PlatformMetric::SignalTypesInUse, $inUse);
            $written[PlatformMetric::SignalTypesInUse->value] = count($inUse);
        }

        return $written;
    }

    /**
     * `signal_definitions.id` => the preset key it was cloned from, for the ones that were.
     *
     * Only the clones: anything absent from the map is a type a team built themselves, which the
     * caller reads as `own`. Platform presets themselves are excluded — a preset is not in use by
     * anybody, it is the thing being used.
     *
     * @return array<int, string>
     */
    private function presetKeysByDefinition(): array
    {
        $map = [];

        foreach (DB::table('signal_definitions')
            ->whereNotNull('team_id')
            ->whereNotNull('preset_key')
            ->select(['id', 'preset_key'])
            ->get() as $row) {
            $map[(int) $row->id] = (string) $row->preset_key;
        }

        return $map;
    }

    /**
     * Active tenant types now, counted by the preset they came from (or `own`).
     *
     * `whereNull('deleted_at')` as well as `is_active`, because a soft-deleted type is not in use
     * and the global scope is off here.
     *
     * @return array<string, int>
     */
    private function activeDefinitionPresets(): array
    {
        $counts = [];

        foreach (DB::table('signal_definitions')
            ->whereNotNull('team_id')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->selectRaw('preset_key, count(*) as aggregate')
            ->groupBy('preset_key')
            ->get() as $row) {
            $key = $row->preset_key === null ? 'own' : (string) $row->preset_key;
            $counts[$key] = ($counts[$key] ?? 0) + (int) $row->aggregate;
        }

        return $counts;
    }

    /* ----------------------------------------------------------------- Helpers */

    /**
     * The day's inclusive instant bounds.
     *
     * Inclusive at both ends rather than half-open, because these reach `whereBetween` on
     * timestamp columns — the half-open discipline belongs to {@see DateRange},
     * and a day is the one window where the two forms cannot disagree: the upper bound is the last
     * second of the day, and nothing is stored with sub-second precision.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(CarbonImmutable $day): array
    {
        return [$day, $day->addDay()->subSecond()];
    }

    /**
     * Distinct tenants in `$cohort` that have at least one row in `$table`.
     *
     * A query-builder count rather than Eloquent, because the five tables involved belong to five
     * modules and the funnel does not care about any of their models — only that a row exists.
     *
     * @param  list<int>  $cohort
     */
    private function distinctTeams(string $table, array $cohort): int
    {
        if ($cohort === []) {
            return 0;
        }

        return (int) DB::table($table)->whereIn('team_id', $cohort)->distinct()->count('team_id');
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $column
     * @return array<string, int>
     */
    private function groupedCounts(Builder $query, string $column): array
    {
        return $query
            ->selectRaw($column.', COUNT(*) as aggregate')
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->mapWithKeys(static fn (mixed $value, mixed $key): array => [(string) $key => (int) $value])
            ->all();
    }

    /**
     * The grants in force for a set of tenants at an instant, keyed by tenant id.
     *
     * One query per chunk rather than a relation, for the reason `PlanResolver` gives: a lazy
     * relation on a model hydrated as part of a collection is an exception under
     * `preventLazyLoading()`, and this walks every tenant.
     *
     * @param  array<int, int|string>  $teamIds
     * @return array<int, PlanGrant>
     */
    private function grantsInForce(array $teamIds, CarbonImmutable $at): array
    {
        if ($teamIds === []) {
            return [];
        }

        $grants = [];

        foreach (PlanGrant::query()
            ->withoutGlobalScopes()
            ->whereIn('team_id', $teamIds)
            ->inForce($at)
            ->get() as $grant) {
            // `inForce` orders newest window first, so the first one seen per tenant wins — the
            // same precedence the resolver applies.
            $grants[(int) $grant->team_id] ??= $grant;
        }

        return $grants;
    }

    /**
     * The subscription row whose **window** contains the instant, keyed by tenant id.
     *
     * The window comes from the row (`created_at`, `ends_at`); the status is read as it stands,
     * because Stripe's status is not historised. See the class note, group 2.
     *
     * @param  array<int, int|string>  $teamIds
     * @return array<int, Subscription>
     */
    private function subscriptionsInForce(array $teamIds, CarbonImmutable $at): array
    {
        if ($teamIds === []) {
            return [];
        }

        $teamKey = (new Team)->getForeignKey();
        $subscriptions = [];

        foreach (Subscription::query()
            ->whereIn($teamKey, $teamIds)
            ->where('created_at', '<=', $at)
            ->where(static fn (object $query) => $query
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>', $at))
            ->orderByDesc('created_at')
            ->get() as $subscription) {
            $subscriptions[(int) $subscription->getAttributes()[$teamKey]] ??= $subscription;
        }

        return $subscriptions;
    }

    /**
     * What one tenant was entitled to, and whether anybody was paying for it, at an instant.
     *
     * Precedence matches `PlanResolver`: **grant, subscription, trial, default**. The second
     * half — `paying` — is the judgement a resolver does not have to make, and it is written down
     * here: a Stripe subscription means somebody is paying; a **grant** means somebody is paying
     * only when the tenant is on manual billing, because that is what an invoiced contract is. A
     * goodwill grant while a billing problem is sorted out is not revenue, and counting it would
     * put MRR on the dashboard that nobody will ever invoice.
     *
     * @return array{plan: string, amount: int, paying: bool}
     */
    private function positionFor(
        Team $team,
        ?PlanGrant $grant,
        ?Subscription $subscription,
        CarbonImmutable $at,
    ): array {
        if ($grant instanceof PlanGrant) {
            $paying = BillingMode::of($team) === BillingMode::Manual;

            return [
                // `->value`: `PlanGrant::$plan` is cast to a `PlanKey`, and the rollup's dimensions
                // are strings.
                'plan' => $grant->plan->value,
                'amount' => $paying ? $this->priceOf($grant->plan->value) : 0,
                'paying' => $paying,
            ];
        }

        if ($subscription instanceof Subscription) {
            $status = (string) $subscription->getAttributes()['stripe_status'];
            $endsAt = $subscription->getAttributes()['ends_at'] ?? null;

            // **A cancelled subscription still produced revenue until it ended**, and that is the
            // correction worth stating: `stripe_status` is not historised, so every subscription a
            // customer has since cancelled reads as `canceled` today — and treating that as dead
            // made a backfill report every churned customer as never having paid us, with no
            // churn in the series to explain where they went. `subscriptionsInForce()` has already
            // restricted the set to rows whose window contains the day; within it, only two states
            // mean no money ever changed hands.
            //
            //  - `incomplete` / `incomplete_expired` — the first payment was never made.
            //  - `canceled` with **no** end date — cancelled immediately, with no paid period.
            //
            // Everything else in the window did produce revenue, including a subscription that was
            // past due at the time: past due is money owed, not money that never existed.
            $dead = in_array($status, ['incomplete', 'incomplete_expired'], true)
                || ($status === 'canceled' && $endsAt === null);

            if (! $dead) {
                $plan = $this->plans->fromStripePrice($subscription->stripe_price);
                $key = $plan?->key->value ?? $this->cheapestPaidPlan();

                return ['plan' => $key, 'amount' => $this->priceOf($key), 'paying' => true];
            }
        }

        $trialEndsAt = $team->trial_ends_at;

        if ($trialEndsAt !== null && CarbonImmutable::instance($trialEndsAt)->utc()->isAfter($at)) {
            return ['plan' => $this->plans->trial()->key->value, 'amount' => 0, 'paying' => false];
        }

        return ['plan' => $this->plans->default()->key->value, 'amount' => 0, 'paying' => false];
    }

    /**
     * A plan's list price in minor units.
     *
     * A plan priced **on application** (Enterprise, `amount: null`) contributes **zero** rather
     * than a guess. The contract's real number is not in the system — a grant carries allowances,
     * not a price — so MRR is a floor for any month with an Enterprise tenant in it, and
     * `PayingTeams` is what stops that tenant disappearing from the revenue report entirely.
     * Recorded as a deviation in the module doc, because it is the kind of gap that reads as a bug.
     */
    private function priceOf(string $plan): int
    {
        return $this->plans->fromKey($plan)->amount ?? 0;
    }

    private function cheapestPaidPlan(): string
    {
        $purchasable = $this->plans->purchasable();

        return ($purchasable[0] ?? $this->plans->default())->key->value;
    }

    /** `t<id>|<plan>` — the per-tenant MRR dimension. See {@see self::rollUpMovement()}. */
    private function teamPlanDimension(int $teamId, string $plan): string
    {
        return PlatformMetric::teamDimension($teamId).'|'.$plan;
    }

    /**
     * @return array{0: int|null, 1: string}
     */
    private function splitTeamPlanDimension(string $dimension): array
    {
        $owner = Str::contains($dimension, '|') ? (string) Str::before($dimension, '|') : $dimension;
        $plan = Str::contains($dimension, '|')
            ? (string) Str::after($dimension, '|')
            : PlanKey::Free->value;

        return [PlatformMetric::teamIdFromDimension($owner), $plan];
    }
}
