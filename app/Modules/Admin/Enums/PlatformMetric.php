<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

use App\Modules\Admin\Services\Platform\PlatformMetricStore;
use App\Modules\Admin\Services\Platform\PlatformRollup;
use App\Modules\Reporting\Support\DateRange;

/**
 * Every figure the platform rolls up nightly (Phase 19c), with **its definition written beside
 * it**.
 *
 * The definition is not documentation politeness. Reporting's risk is that a tenant dashboard's
 * charts disagree with one another; the platform's is worse — that an aggregate quietly answers a
 * *tenant-shaped* question and nobody notices, because there is no customer to complain. So each
 * case states what it counts and when it counts it, {@see self::definition()} is rendered on the
 * screen next to the number, and it travels into the CSV's caption.
 *
 * ## Two shapes of date, and the difference matters
 *
 * - **Event-shaped** (the default): the row's `date` is the UTC day the thing happened, and it can
 *   never change afterwards. Yesterday's signups are yesterday's signups for ever.
 * - **Cohort-shaped** ({@see self::isCohort()}): the row's `date` is the day a cohort *signed up*,
 *   and the value says how far that cohort has since got. A team that signed up a fortnight ago
 *   and runs its first discovery today changes a row dated a fortnight ago. Those rows are
 *   therefore **recomputed** over a trailing window every night rather than written once — see
 *   {@see PlatformRollup}. Treating a cohort figure as event-shaped is the exact mistake this
 *   split exists to prevent: the onboarding funnel would permanently under-report every step after
 *   the first.
 *
 * ## Units
 *
 * {@see self::kind()}, never inferred from the name. Money is minor units; provider cost is cost
 * **micro-units**, because that is what the ledgers store.
 *
 * ## Windows
 *
 * Nothing here knows about windows. A metric is a day's value; narrowing to a window is
 * {@see DateRange}'s job and the store's, so the UTC discipline has one implementation shared with
 * Reporting rather than a second one here.
 */
enum PlatformMetric: string
{
    /* ------------------------------------------------------------ Bookkeeping */

    /**
     * **A marker that the day was rolled up at all**, written for every day the command touches.
     *
     * It exists because of one bug that is invisible until it matters. A snapshot metric is read
     * from the window's last *populated* day, and the snapshots only write rows for non-zero
     * dimensions — so the night the platform's last paying tenant cancels, MRR writes nothing, the
     * reader falls back to the last day that had revenue, and the dashboard reports the old figure
     * for as long as the window still contains it. A frozen number is worse than a zero.
     *
     * With a marker, "the last day we know about" is a fact about the rollup rather than a fact
     * about one metric, and an emptied snapshot correctly reads as nothing.
     */
    case RollupDay = 'rollup_day';

    /**
     * The same marker for the **point-in-time** group — dunning, seats, allowance consumption and
     * reported overage.
     *
     * A second marker rather than one, because the two groups do not cover the same days: a
     * backfill reconstructs MRR for an old day but cannot invent that day's Stripe statuses, so it
     * writes {@see self::RollupDay} and not this. Reading a point-in-time snapshot against the
     * wrong marker would report "no dunning" for every reconstructed day.
     */
    case RollupStateDay = 'rollup_state_day';

    /* ---------------------------------------------------------------- Growth */

    /** Dimension: market (`uk`, `uae`). */
    case Signups = 'signups';

    case TrialStarts = 'trial_starts';

    case Activations = 'activations';

    case TrialConversions = 'trial_conversions';

    /** Dimension: `d<n>` — how many days after signing up the team converted. */
    case ConversionDay = 'conversion_day';

    /* ----------------------------------------------- Onboarding funnel (cohort) */

    case FunnelSignedUp = 'funnel_signed_up';

    case FunnelProfile = 'funnel_profile';

    case FunnelIcp = 'funnel_icp';

    case FunnelDiscovery = 'funnel_discovery';

    case FunnelLeadWorked = 'funnel_lead_worked';

    case FunnelOutreach = 'funnel_outreach';

    /* --------------------------------------------------------------- Revenue */

    /** Dimension: plan key. */
    case Mrr = 'mrr';

    /**
     * Dimension: `t<id>|<plan>`. The per-tenant series movement and margin are both computed from.
     *
     * The plan is in the dimension because **churn has to be attributed to the plan the tenant
     * left**, and by the time they have churned nothing else remembers what it was.
     */
    case TeamMrr = 'team_mrr';

    /** Dimension: plan key. Positive. */
    case MrrNew = 'mrr_new';

    /** Dimension: plan key. Positive. */
    case MrrExpansion = 'mrr_expansion';

    /** Dimension: plan key. **Negative**, as the movement is. */
    case MrrContraction = 'mrr_contraction';

    /** Dimension: plan key (the plan they left). **Negative**. */
    case MrrChurn = 'mrr_churn';

    /** Dimension: plan key. Positive. */
    case MrrReactivation = 'mrr_reactivation';

    /** Dimension: plan key. */
    case PayingTeams = 'paying_teams';

    /**
     * Dimension: `t<id>`, value 1. **Whether somebody was paying for this tenant**, irrespective
     * of price.
     *
     * Separate from {@see self::TeamMrr} because a plan priced *on application* and a manually
     * granted plan both contribute **zero** to MRR — so a retention figure computed from MRR alone
     * would report every Enterprise and every invoiced customer as having never paid us. Retention
     * is about customers, not about revenue, so it reads this.
     */
    case TeamPaying = 'team_paying';

    /** Dimension: Stripe status (`past_due`, `unpaid`, `incomplete`). */
    case DunningSubscriptions = 'dunning_subscriptions';

    /** Dimension: billing metric (`leads`, `ai_credits`). */
    case OverageRevenue = 'overage_revenue';

    case SeatsEntitled = 'seats_entitled';

    case SeatsUsed = 'seats_used';

    /* -------------------------------------------------------- Pricing signal */

    /** Dimension: billing metric. */
    case AllowanceCeiling = 'allowance_ceiling';

    /** Dimension: `<metric>:<band>`, bands `0-25`, `25-50`, `50-75`, `75-100`, `100+`. */
    case AllowanceBand = 'allowance_band';

    /* ------------------------------------------------------------------ Cost */

    /** Dimension: model. */
    case AiCostMicros = 'ai_cost_micros';

    /** Dimension: prompt key — the *work* the call did, not the model that did it. */
    case AiCostMicrosByWork = 'ai_cost_micros_by_work';

    /** Dimension: AI request status (`success`, `failed`). */
    case AiRequests = 'ai_requests';

    case AiLatencyMsTotal = 'ai_latency_ms_total';

    /** Dimension: provider key — see {@see PlatformProvider}. */
    case ProviderCostMicros = 'provider_cost_micros';

    /** Dimension: provider key. */
    case ProviderCalls = 'provider_calls';

    /** Dimension: provider key. */
    case ProviderFailures = 'provider_failures';

    /** Dimension: lead source. */
    case LeadsCreated = 'leads_created';

    /** Dimension: `t<id>|<CUR>`, or `unattributed|<CUR>` where no tenant owns the spend. */
    case TeamCostMicros = 'team_cost_micros';

    /* -------------------------------------------------------------- Outreach */

    /** Dimension: channel. */
    case OutreachSent = 'outreach_sent';

    /** Dimension: channel. */
    case OutreachAttempts = 'outreach_attempts';

    /** Dimension: `<channel>:<outcome>`. */
    case OutreachOutcomes = 'outreach_outcomes';

    /* --------------------------------------------------------------- Signals */

    /**
     * Signal types in use across the platform. Dimension: the **preset key** a type was cloned
     * from, or `own` for one a team built themselves (S15 task 6).
     *
     * This is the figure that says which of the library's presets anybody is actually running —
     * which, with the three below, is what tells the business which packs to invest in. A preset
     * nobody has cloned in six months is a preset to retire from the library, and nothing else in
     * the product can say so.
     */
    case SignalTypesInUse = 'signal_types_in_use';

    /**
     * Signals found on the day. Dimension: the preset key the type came from, or `own`.
     *
     * Counted per **cluster representative**, like every other signal figure in the product, so a
     * story that gets picked up widely does not make a preset look eight times as productive as one
     * whose stories are not.
     */
    case SignalsFound = 'signals_found';

    /**
     * How far those signals got. Dimension: `<preset key>:<kept|converted>`.
     *
     * One metric with a compound dimension rather than two, for the reason
     * {@see self::OutreachOutcomes} is: the two numbers are only read beside each other, and
     * splitting them would be two queries and two chances for a window to disagree.
     *
     * Scoped by when the signal was **found**, not by when it converted — the cohort rule every
     * signal funnel in this codebase follows, so the platform's figures and a tenant's agree.
     */
    case SignalOutcomes = 'signal_outcomes';

    /**
     * The same signals by the **source** that found them. Dimension: a `SignalSourceKind` value.
     *
     * Separate from the preset dimension because the two answer different questions: one is about
     * what we offer teams to look for, the other about where it is worth looking at all.
     */
    case SignalsBySource = 'signals_by_source';

    public function label(): string
    {
        return match ($this) {
            self::RollupDay => 'Rolled up',
            self::RollupStateDay => 'State rolled up',
            self::Signups => 'Signups',
            self::TrialStarts => 'Trials started',
            self::Activations => 'Activations',
            self::TrialConversions => 'Trial conversions',
            self::ConversionDay => 'Conversions by day',
            self::FunnelSignedUp => 'Signed up',
            self::FunnelProfile => 'Saved a business profile',
            self::FunnelIcp => 'Defined an ideal customer',
            self::FunnelDiscovery => 'Ran a discovery',
            self::FunnelLeadWorked => 'Worked a prospect',
            self::FunnelOutreach => 'Approached a prospect',
            self::Mrr => 'MRR',
            self::TeamMrr => 'MRR per tenant',
            self::MrrNew => 'New',
            self::MrrExpansion => 'Expansion',
            self::MrrContraction => 'Contraction',
            self::MrrChurn => 'Churn',
            self::MrrReactivation => 'Reactivation',
            self::PayingTeams => 'Paying tenants',
            self::TeamPaying => 'Paying tenants (per tenant)',
            self::DunningSubscriptions => 'Subscriptions in dunning',
            self::OverageRevenue => 'Overage revenue',
            self::SeatsEntitled => 'Seats entitled',
            self::SeatsUsed => 'Seats used',
            self::AllowanceCeiling => 'Tenants at the ceiling',
            self::AllowanceBand => 'Allowance consumption',
            self::AiCostMicros => 'AI cost by model',
            self::AiCostMicrosByWork => 'AI cost by work',
            self::AiRequests => 'AI calls',
            self::AiLatencyMsTotal => 'AI latency (total ms)',
            self::ProviderCostMicros => 'Provider cost',
            self::ProviderCalls => 'Provider calls',
            self::ProviderFailures => 'Provider failures',
            self::LeadsCreated => 'Prospects sourced',
            self::TeamCostMicros => 'Provider cost per tenant',
            self::OutreachSent => 'Messages sent',
            self::OutreachAttempts => 'Approaches logged',
            self::OutreachOutcomes => 'Approach outcomes',
            self::SignalTypesInUse => 'Signal types in use',
            self::SignalsFound => 'Signals found',
            self::SignalOutcomes => 'Signal outcomes',
            self::SignalsBySource => 'Signals by source',
        };
    }

    /**
     * **What this figure counts, and when.** Shown on screen beside the number and written into the
     * CSV caption, so a figure can never be read without its rule.
     */
    public function definition(): string
    {
        return match ($this) {
            self::RollupDay => 'Bookkeeping: the day was rolled up. Nothing reads it as a figure — it is what tells a reader whether a missing snapshot means zero or means not-yet-computed.',
            self::RollupStateDay => 'Bookkeeping: that day had its point-in-time state captured. Only a day rolled up as current has one; a reconstructed day deliberately does not.',
            self::Signups => 'Teams created on the day, by the market they chose at signup.',
            self::TrialStarts => 'Teams created on the day that were given a trial end date. Every self-serve signup gets one, so this differs from signups only for a tenant an operator provisioned with no trial.',
            self::Activations => 'Teams whose first member-driven action on a prospect happened on the day — a note, a task, a stage change, an assignment, a tag or an approach. Counted once per team, ever: discovery and scoring are things Alima did, and a team that has only watched them happen has not activated.',
            self::TrialConversions => 'Teams whose first paid subscription started on the day, whether or not the trial had run out.',
            self::ConversionDay => 'The same conversions, grouped by how many whole days after signing up they happened. Day 0 is the same day.',
            self::FunnelSignedUp => 'Teams created on the cohort day. The funnel\'s denominator.',
            self::FunnelProfile => 'Of that cohort, how many have since saved a business profile.',
            self::FunnelIcp => 'Of that cohort, how many have since saved an ideal customer profile.',
            self::FunnelDiscovery => 'Of that cohort, how many have since started a discovery run — started, not completed, because a run that failed still means they tried.',
            self::FunnelLeadWorked => 'Of that cohort, how many have since taken a member-driven action on a prospect. The same rule as Activations.',
            self::FunnelOutreach => 'Of that cohort, how many have since logged an approach to a prospect.',
            self::Mrr => 'Monthly recurring revenue in force at the end of the day, by the plan actually entitling the team — so a granted plan counts at the granted plan\'s list price and a trial counts as nothing. Teams on a free or price-on-application plan contribute zero rather than being omitted.',
            self::TeamMrr => 'The same figure per tenant. Kept because movement and gross margin are both differences between one tenant\'s days, which a plan-level total cannot answer.',
            self::MrrNew => 'MRR from tenants that had none yesterday and have never had any.',
            self::MrrExpansion => 'The increase, for tenants that had MRR yesterday and more today.',
            self::MrrContraction => 'The decrease, for tenants that had MRR yesterday and less — but not none — today. Negative.',
            self::MrrChurn => 'The whole of yesterday\'s MRR for tenants that have none today, against the plan they left. Negative.',
            self::MrrReactivation => 'MRR from tenants that had none yesterday but had some on an earlier day. The line that stops a returning customer being counted as new.',
            self::TeamPaying => 'The same per tenant, so retention can ask whether a customer has ever paid us without going through a price — which an Enterprise or invoiced tenant does not have.',
            self::PayingTeams => 'Tenants whose plan was in force and paid for at the end of the day, by plan — a Stripe subscription, or a granted plan on manual billing. Deliberately not the same as MRR above zero: a plan priced on application contributes nothing to MRR and is counted here, which is what stops an Enterprise tenant vanishing from the revenue report.',
            self::DunningSubscriptions => 'Subscriptions in a failed-payment state at the end of the day, by Stripe\'s own status. A snapshot, not an event count: the same subscription appears on every day it stays stuck.',
            self::OverageRevenue => 'Overage already reported to Stripe for billing periods open on the day, priced at the configured per-unit amount. What has been metered, not what has been collected.',
            self::SeatsEntitled => 'Seats the plans in force entitle tenants to. A tenant on an unlimited plan contributes its seats used, so the ratio cannot exceed 100% for a reason that is not about utilisation.',
            self::SeatsUsed => 'Memberships across every tenant at the end of the day.',
            self::AllowanceCeiling => 'Tenants whose usage of the metric had reached or passed their allowance for the billing period open on the day. Tenants with an unlimited allowance are excluded — they cannot hit a ceiling.',
            self::AllowanceBand => 'The same tenants spread across five bands of allowance consumed. The shape of this distribution is what says a plan is priced or sized wrong; a mean would hide it.',
            self::AiCostMicros => 'Provider cost of AI calls made on the day, by model, in cost micro-units of the currency the provider billed in.',
            self::AiCostMicrosByWork => 'The same cost by prompt key — what the call was for, which is the figure that says whether scoring or drafting is the expensive part.',
            self::AiRequests => 'AI calls made on the day, by outcome. Cache hits are included and cost nothing, so this is not a proxy for spend.',
            self::AiLatencyMsTotal => 'Milliseconds of AI latency summed over the day, across every call including cache hits and failures. Divided by the call count it is therefore a mean over all calls rather than over provider round trips; meaningless alone.',
            self::ProviderCostMicros => 'Cost of external calls on the day by provider, in cost micro-units. Free providers record zero, which is a fact rather than a gap.',
            self::ProviderCalls => 'Calls to each provider on the day — one per discovery or signal run, one per AI request.',
            self::ProviderFailures => 'Of those, how many finished in a failed state.',
            self::LeadsCreated => 'Prospects persisted on the day, by the source that found them.',
            self::TeamCostMicros => 'Provider cost on the day attributed to one tenant, split by the currency the provider billed in. Spend with no tenant behind it — a platform job, a backfill — is recorded under `unattributed` and never spread across tenants, because a margin figure that silently absorbed it would flatter every customer equally.',
            self::OutreachSent => 'Messages Alima actually sent on the day, by channel.',
            self::OutreachAttempts => 'Approaches logged on the day, by channel — including the ones a member made themselves and recorded.',
            self::OutreachOutcomes => 'The same approaches by channel and recorded outcome, which is where bounces, complaints and opt-outs come from.',
            self::SignalTypesInUse => 'Active signal types across every tenant, by the library preset they were cloned from — or “own” for one a team built themselves. A preset nobody has cloned is a preset to retire from the library, and nothing else in the product can say so.',
            self::SignalsFound => 'Signals found on the day, by the preset their type came from. Counted per event rather than per article, like every other signal figure.',
            self::SignalOutcomes => 'How far those signals got: kept, and converted into a lead. Scoped by when the signal was found, so a conversion counts under the day and the preset that found it.',
            self::SignalsBySource => 'The same signals by the source that found them, which is the figure that says where it is worth looking at all.',
        };
    }

    public function kind(): PlatformMetricKind
    {
        return match ($this) {
            self::Mrr, self::TeamMrr, self::MrrNew, self::MrrExpansion, self::MrrContraction,
            self::MrrChurn, self::MrrReactivation, self::OverageRevenue => PlatformMetricKind::Money,

            self::AiCostMicros, self::AiCostMicrosByWork, self::ProviderCostMicros,
            self::TeamCostMicros => PlatformMetricKind::Micros,

            self::AiLatencyMsTotal => PlatformMetricKind::Milliseconds,

            default => PlatformMetricKind::Count,
        };
    }

    /**
     * Whether the row's `date` is a **signup cohort** day rather than the day something happened.
     *
     * The six funnel steps are the only cohort-shaped figures, and they are why the rollup
     * recomputes a trailing window instead of only writing yesterday. See the class note.
     */
    public function isCohort(): bool
    {
        return match ($this) {
            self::FunnelSignedUp, self::FunnelProfile, self::FunnelIcp, self::FunnelDiscovery,
            self::FunnelLeadWorked, self::FunnelOutreach => true,
            default => false,
        };
    }

    /**
     * Whether a day's value is a **state** rather than something that happened on that day.
     *
     * This decides how a window is collapsed, and getting it wrong is the easiest way to publish a
     * confident lie: summing MRR over thirty days reports thirty months of revenue. A snapshot
     * metric is read from the **last day in the window** that has a row; a flow metric is summed.
     *
     * Decided here rather than at each call site so no builder can collapse a series the wrong
     * way — {@see PlatformMetricStore} reads this and
     * exposes one `total()`.
     *
     * The five movements are flows even though MRR is a state: "new MRR this month" is the sum of
     * each day's new MRR, which is exactly what a sum of daily differences gives.
     */
    public function isSnapshot(): bool
    {
        return match ($this) {
            self::Mrr, self::TeamMrr, self::TeamPaying, self::PayingTeams,
            self::DunningSubscriptions, self::SeatsEntitled, self::SeatsUsed,
            self::AllowanceCeiling, self::AllowanceBand => true,

            // `usage_meters.reported` is a **running total** for the open billing period, so every
            // night re-records the whole month's overage so far. Summed over a window that would
            // be thirty copies of the same money.
            self::OverageRevenue => true,

            default => false,
        };
    }

    /**
     * Whether this figure can only honestly be written for a day the rollup is treating as
     * **current**.
     *
     * Nothing in the product historises `subscriptions.stripe_status`, `team_user`,
     * `usage_meters.used` or `usage_meters.reported`, so recomputing an old day would stamp
     * today's state onto it. The command skips these on a backfill — neither writing nor deleting
     * — and {@see self::marker()} is what lets a reader tell that apart from a zero.
     */
    public function isPointInTime(): bool
    {
        return match ($this) {
            self::DunningSubscriptions, self::SeatsUsed, self::SeatsEntitled,
            self::AllowanceCeiling, self::AllowanceBand, self::OverageRevenue => true,
            default => false,
        };
    }

    /**
     * The bookkeeping marker that says whether a day holds this metric at all.
     *
     * Only a snapshot needs one. A flow metric's absence is a zero — nothing happened — while a
     * snapshot's absence is ambiguous between "the state is zero" and "we have not computed it",
     * and reading the second as the first is how a dashboard freezes. See {@see self::RollupDay}.
     */
    public function marker(): ?self
    {
        if (! $this->isSnapshot()) {
            return null;
        }

        return $this->isPointInTime() ? self::RollupStateDay : self::RollupDay;
    }

    /** What the `dimension` column holds for this metric, for the screen's column heading. */
    public function dimensionLabel(): ?string
    {
        return match ($this) {
            self::Signups => 'Market',
            self::ConversionDay => 'Days to convert',
            self::Mrr, self::MrrNew, self::MrrExpansion, self::MrrContraction, self::MrrChurn,
            self::MrrReactivation, self::PayingTeams => 'Plan',
            self::TeamMrr, self::TeamPaying => 'Tenant',
            self::DunningSubscriptions => 'Stripe status',
            self::OverageRevenue, self::AllowanceCeiling => 'Metric',
            self::AllowanceBand => 'Metric and band',
            self::AiCostMicros => 'Model',
            self::AiCostMicrosByWork => 'Prompt',
            self::AiRequests => 'Outcome',
            self::ProviderCostMicros, self::ProviderCalls, self::ProviderFailures => 'Provider',
            self::LeadsCreated => 'Source',
            self::TeamCostMicros => 'Tenant and currency',
            self::OutreachSent, self::OutreachAttempts => 'Channel',
            self::OutreachOutcomes => 'Channel and outcome',
            self::SignalTypesInUse, self::SignalsFound => 'Library preset, or “own”',
            self::SignalOutcomes => 'Library preset and outcome',
            self::SignalsBySource => 'Signal source',
            default => null,
        };
    }

    /**
     * The funnel's six steps in order — the single report that says whether the product's first
     * hour works.
     *
     * @return list<self>
     */
    public static function funnel(): array
    {
        return [
            self::FunnelSignedUp,
            self::FunnelProfile,
            self::FunnelIcp,
            self::FunnelDiscovery,
            self::FunnelLeadWorked,
            self::FunnelOutreach,
        ];
    }

    /**
     * The five MRR movements, in the order a revenue report reads them.
     *
     * @return list<self>
     */
    public static function movement(): array
    {
        return [
            self::MrrNew,
            self::MrrExpansion,
            self::MrrContraction,
            self::MrrChurn,
            self::MrrReactivation,
        ];
    }

    /**
     * The dimension for one tenant. One helper rather than string concatenation at eleven call
     * sites: a dimension written `team:7` in one place and `t7` in another is a figure that reads
     * as zero and errors nowhere.
     */
    public static function teamDimension(int $teamId): string
    {
        return 't'.$teamId;
    }

    /** The tenant id back out of a `t<id>` dimension, or null when it is not one. */
    public static function teamIdFromDimension(string $dimension): ?int
    {
        return preg_match('/^t(\d+)$/', $dimension, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $metric): string => $metric->value, self::cases());
    }
}
