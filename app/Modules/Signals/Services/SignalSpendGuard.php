<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Actions\PreviewSignalDefinitionAction;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Contracts\SignalQuotaSource;
use App\Modules\Signals\Data\SignalAllowance;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalPlaybook;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place a Signals limit is decided (S16 task 2).
 *
 * **Enforcement is here and not in middleware, and that is ADR 0012's argument rather than a
 * preference.** Three of the four ways this module spends money have no request behind them: the
 * hourly `signals:dispatch-due`, the nightly watchlist sync, and a queued
 * {@see RunSignalWatchAction} are all workers. A middleware limit would guard the one case that is
 * cheapest to guard — somebody pressing a button — and leave the three that actually run up a bill.
 * So every ceiling is asked for at the point of spend, and the route middleware beside some of them
 * is the courtesy that gives a member a sentence rather than the thing that makes the refusal true.
 *
 * The allowance comes from a {@see SignalQuotaSource} — the module's own config floors by default, a
 * team's plan once Billing is registered — and the usage from {@see SignalUsageCounter}, which reads
 * this module's ledgers. Neither this class nor anything downstream of it knows what a plan is.
 *
 * **A ceiling refuses; a partial allowance clamps.** The two are different answers to different
 * situations and conflating them is how a team near the edge of a monthly allowance gets nothing
 * instead of what they are still entitled to. So {@see self::assertSourceQueriesRemain()} refuses a
 * run with no allowance left, and {@see self::maxQueriesPerRun()} hands back a *smaller* per-run
 * ceiling when there is some — the same split `LeadQuota::clamp()` draws beside
 * `DiscoveryLimitGuard::ensureWithinAllowance()`.
 */
final class SignalSpendGuard
{
    /**
     * The allowance, per team id, for the life of this instance.
     *
     * **Bound `scoped()` rather than `singleton()`, for the reason `PlanResolver`'s docblock gives
     * about the same trap:** a singleton would hold this memo for the life of a `queue:work`
     * process, and two of the three callers that matter are workers — so the second watch run in
     * that process would be held to the first team's plan. `scoped` is one request or one job,
     * which is exactly how long an allowance can be treated as fixed.
     *
     * Memoised at all because an allowance is **not free**: the plan-backed source reads the price
     * list and the team's subscription, and the guard asks for it from nine different methods —
     * `assertSourceQueriesRemain()` and `maxQueriesPerRun()` alone used to ask for it twice in one
     * run (found in the S16 review). The **usage** is deliberately not memoised: a ceiling is a
     * fact about a plan and a count is a fact about right now.
     *
     * There is deliberately no `forget()`. `app()->forgetInstance()` already drops the whole guard,
     * which is what a test changing a plan mid-request wants, and a second way to do the same thing
     * would be a public method nothing calls.
     *
     * @var array<int, SignalAllowance>
     */
    private array $allowances = [];

    public function __construct(
        private readonly SignalQuotaSource $quota,
        private readonly SignalUsageCounter $usage,
    ) {}

    /**
     * Everything this team is allowed, in one read. Exposed so a screen can render the ceilings it
     * is about to be held to without each one costing a separate resolution.
     */
    public function allowanceFor(Team $team): SignalAllowance
    {
        return $this->allowances[(int) $team->getKey()] ??= $this->quota->allowanceFor($team);
    }

    // --- Standing ceilings: how many instructions to spend a team may keep ---

    /**
     * Refuse a new signal type once the team is at its ceiling.
     *
     * Create only. An existing type is already counted, so editing one must not fail because the
     * team is at the limit — and soft-deleted rows do not count, because they produce no searches.
     * Platform presets are not counted either: they have no `team_id`.
     *
     * @throws SignalsException
     */
    public function assertRoomForDefinition(Team $team): void
    {
        $max = $this->allowanceFor($team)->maxDefinitions;

        if ($max === null) {
            return;
        }

        if (self::countFor(SignalDefinition::query(), $team) >= $max) {
            throw SignalsException::tooManyDefinitions($max);
        }
    }

    /**
     * Refuse a new watch once the team is at its ceiling.
     *
     * A watch is a standing instruction to spend money, so the ceiling is on *creating* them rather
     * than only on running them — the per-watch run throttle does nothing about a team with two
     * hundred watches.
     *
     * @throws SignalsException
     */
    public function assertRoomForWatch(Team $team): void
    {
        $max = $this->allowanceFor($team)->maxWatches;

        if ($max === null) {
            return;
        }

        if (self::countFor(SignalWatch::query(), $team) >= $max) {
            throw SignalsException::tooManyWatches($max);
        }
    }

    /**
     * How many accounts one watchlist may hold.
     *
     * A number rather than a refusal, because both callers need the number: the manual add refuses
     * past it, and the pipeline sync **stops adding** at it — a mirrored pipeline grows on its own,
     * so following a team's leads to four figures would be a cost nobody chose.
     *
     * Floored at one so a misconfigured zero cannot empty every list on the next sync.
     */
    public function maxWatchlistEntries(Team $team): int
    {
        return max(1, $this->allowanceFor($team)->maxWatchlistEntries ?? PHP_INT_MAX);
    }

    // --- Monthly allowances: what a team may spend in a calendar month ---

    /**
     * Refuse a run once the team has spent its monthly source-query allowance.
     *
     * Raised **before** the run does any external work, so the refusal is recorded on the run and
     * nothing is half-done. A team on a ceiling gets one clean failed run per cadence with a
     * sentence on it, which is what a watch card can show; a half-run would read as the news
     * provider having broken.
     *
     * @throws SignalsException
     */
    public function assertSourceQueriesRemain(Team $team): void
    {
        $limit = $this->allowanceFor($team)->monthlySourceQueries;

        if ($limit === null) {
            return;
        }

        if ($this->usage->sourceQueriesThisMonth($team) >= $limit) {
            throw SignalsException::sourceQuotaSpent($limit);
        }
    }

    /**
     * Source queries this team's runs may still make this month, or null when unlimited.
     */
    public function remainingSourceQueries(Team $team): ?int
    {
        return SignalAllowance::remaining(
            $this->allowanceFor($team)->monthlySourceQueries,
            $this->usage->sourceQueriesThisMonth($team),
        );
    }

    /**
     * The per-run query ceiling for this team: the module's own bound, clamped by what is left of
     * the monthly allowance.
     *
     * The clamp is what makes a team near the edge of their allowance get the coverage they are
     * still entitled to rather than a refusal. The run already reports a trimmed plan on the watch
     * card (`SignalQueryPlan::wasTrimmed()`), so a smaller ceiling is visible rather than silent —
     * which is the condition under which clamping is honest.
     *
     * Floored at one: a team with nothing left is refused by
     * {@see self::assertSourceQueriesRemain()}, and returning zero here would instead build an empty
     * plan and complete a run that looked like a quiet news day.
     */
    public function maxQueriesPerRun(Team $team): int
    {
        $ceiling = max(1, (int) config('signals.max_queries_per_run', 40));
        $remaining = $this->remainingSourceQueries($team);

        if ($remaining === null) {
            return $ceiling;
        }

        return max(1, min($ceiling, $remaining));
    }

    /**
     * Refuse a preview once the team has spent its monthly preview allowance.
     *
     * The second of two ceilings on the same button, and they answer different questions. The daily
     * micro-unit budget ({@see PreviewSignalDefinitionAction}) is about an *afternoon* — twelve
     * members each inside their own rate limit emptying an allowance in one sitting. This one is
     * about a *month*, and it is the figure a plan sets.
     *
     * @throws SignalsException
     */
    public function assertPreviewQueriesRemain(Team $team): void
    {
        $limit = $this->allowanceFor($team)->monthlyPreviewQueries;

        if ($limit === null) {
            return;
        }

        if ($this->usage->previewQueriesThisMonth($team) >= $limit) {
            throw SignalsException::previewQuotaSpent($limit);
        }
    }

    /**
     * Micro-units this team may still spend reading full articles this month, or null when there is
     * no ceiling.
     *
     * A number rather than a refusal, because the article fetch is an **enhancement**: a team at its
     * ceiling keeps receiving signals with the headline and snippet, which is what every signal was
     * before S12. Refusing the run over it would turn a cost ceiling into an outage.
     */
    public function remainingArticleFetchMicros(Team $team): ?int
    {
        return SignalAllowance::remaining(
            $this->allowanceFor($team)->articleFetchMicros,
            $this->usage->articleFetchMicrosThisMonth($team),
        );
    }

    // --- Switches: whether a sub-feature exists for this team at all ---

    /**
     * Whether this team's plan includes playbooks.
     *
     * A boolean as well as the refusal below, because the two callers need different things: the
     * save path refuses and the evaluator **skips silently**, since a rule stored before a
     * downgrade must stop acting without failing the run that found the signal.
     */
    public function allowsPlaybooks(Team $team): bool
    {
        return $this->allowanceFor($team)->playbooksEnabled;
    }

    /**
     * @throws SignalsException when the team's plan does not include playbooks.
     */
    public function assertPlaybooksAllowed(Team $team): void
    {
        if (! $this->allowsPlaybooks($team)) {
            throw SignalsException::playbooksUnavailable();
        }
    }

    /**
     * Refuse a new playbook once the team is at its ceiling.
     *
     * The ceiling itself stays in `config/signals.php` rather than on the allowance: a rule costs
     * evaluation rather than a provider call, so it is a bound on work and confusion rather than on
     * a plan's spending, and the eight figures a plan sets are deliberately the ones that cost
     * money. The plan's answer about playbooks is the switch above.
     *
     * @throws SignalsException
     */
    public function assertRoomForPlaybook(Team $team): void
    {
        $max = max(1, (int) config('signals.playbooks.max_per_team', 10));

        if (self::countFor(SignalPlaybook::query(), $team) >= $max) {
            throw SignalsException::tooManyPlaybooks($max);
        }
    }

    /**
     * Count one team's rows for a standing ceiling.
     *
     * **The model's own global scopes are kept**, with the team predicate stated explicitly beside
     * them. The security review suggested the `withoutGlobalScopes()` + explicit `team_id` shape
     * {@see SignalUsageCounter} uses, so a guard called from a job could not be made permissive by
     * whichever team happens to be ambient — a fair point, and it is **not a drop-in here**, which
     * is worth recording because it looks like one.
     *
     * Dropping the global scopes also drops the **soft-delete** scope, and of the three models
     * counted through this method only `SignalDefinition` soft-deletes: compensating with a blanket
     * `whereNull('deleted_at')` is an unknown column on `signal_watches` and `signal_playbooks`,
     * and a count that cannot run is a ceiling that does not bite (three tests caught it). Doing it
     * per model would mean this method knowing which of its callers soft-delete, which is exactly
     * the knowledge a global scope exists to hold.
     *
     * So the ambient-context concern is answered where it belongs instead: every caller is an
     * Action that already runs inside `TeamContext::run()` for the team it was handed, and the
     * explicit `team_id` means the scope can only ever narrow to the same team or to nothing —
     * never to another's rows.
     *
     * @param  Builder<covariant Model>  $query
     */
    private static function countFor(Builder $query, Team $team): int
    {
        return $query->where('team_id', $team->getKey())->count();
    }

    /**
     * Whether this team's plan includes the inbound events API.
     */
    public function allowsWebhookSource(Team $team): bool
    {
        return $this->allowanceFor($team)->webhookSourceEnabled;
    }

    /**
     * @throws SignalsException when it does not.
     */
    public function assertWebhookSourceAllowed(Team $team): void
    {
        if (! $this->allowsWebhookSource($team)) {
            throw SignalsException::webhookSourceUnavailable();
        }
    }
}
