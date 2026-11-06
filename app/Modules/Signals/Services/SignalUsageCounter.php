<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalPreview;
use App\Modules\Signals\Models\SignalRun;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * What a team has actually spent on Signals over a window, answered by the module that owns the
 * tables (S16 tasks 2 and 3).
 *
 * The Signals counterpart of `LeadSourcing\Services\LeadUsageCounter` and
 * `AiPlatform\Services\AiUsageLogger`, and the same division of labour: this module owns the
 * ledgers and answers the question, {@see SignalSpendGuard} turns the answer into a refusal, and
 * Billing's `UsageLedger` snapshots it into `usage_meters` without learning what a signal is.
 *
 * **Everything here is a read over a ledger. Nothing is a counter the app increments** (ADR 0012).
 * `signal_runs.queries_count` is written once per run by `RunSignalWatchAction` from what the run
 * actually attempted, `signal_previews` gets exactly one row per press, and
 * `signals.article_fetched_at` is a flag that is also a ledger. So a figure here cannot be stale
 * through a forgotten increment, and cannot be doubled through a retried one.
 *
 * **A run that settled as `Failed` is counted**, because it paid Serper the moment it asked —
 * the same hole `LeadUsageCounter` closes by counting failed discovery runs. Two windows where this
 * under-counts, both stated rather than papered over (S16 review):
 *
 * - **A run still `Running` is not counted**, because the figure is written when it settles. The
 *   scheduler can start one run per active watch for a team at once and each will check the
 *   allowance against the same figure, so the overshoot is bounded by `max_watches` × the per-run
 *   ceiling rather than by the allowance. Closing it needs a reservation the run then reconciles,
 *   which is a counter rather than a record (ADR 0012) — and the same trade `DiscoveryLimitGuard`
 *   has made since Phase 16.
 * - **A job that dies outright is not counted either.** `RunSignalWatchJob::failed()` settles the
 *   row without a query figure, because the tally lives in memory until the run settles. A worker
 *   killed mid-run therefore gives its searches away free.
 *
 * Both are under-counts in the customer's favour and neither can double-count, which is the right
 * direction for an allowance to be wrong in.
 *
 * Every query is `withoutGlobalScopes()` and `where('team_id', …)` explicitly, because the callers
 * are a guard inside a queued job and a Billing sync with no team in context.
 */
final class SignalUsageCounter
{
    /**
     * Paid source queries this team's **runs** attempted between `$from` (inclusive) and `$to`
     * (exclusive).
     *
     * **`paid_queries_count`, not `queries_count`** (S16 review). The latter is a *coverage* figure
     * across every source, and three of the five are free — Companies House shares Lead Sourcing's
     * quota, and a feed and the webhook make no request we are billed for. Metering the allowance
     * on coverage charged a team for requests that cost nothing, and on a metered plan produced
     * overage on them.
     *
     * The bounds must already be in **UTC** — they reach a `where` on a column written by queue
     * workers in UTC (CLAUDE.md §2).
     */
    public function sourceQueriesBetween(Team $team, DateTimeInterface $from, DateTimeInterface $to): int
    {
        return (int) SignalRun::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->whereIn('status', [SignalRunStatus::Completed, SignalRunStatus::Failed])
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $from)
            ->where('finished_at', '<', $to)
            ->sum('paid_queries_count');
    }

    /**
     * Paid source queries this team's runs attempted so far this calendar month (UTC) — the window
     * the plan allowance is measured over.
     */
    public function sourceQueriesThisMonth(Team $team): int
    {
        [$start, $end] = self::thisMonth();

        return $this->sourceQueriesBetween($team, $start, $end);
    }

    /**
     * Paid source queries this team's **previews** made so far this calendar month (UTC).
     *
     * A separate figure from the runs above rather than one total, because the two are bounded
     * separately and for different reasons: a run is a standing instruction a team configured, and
     * a preview is a button somebody is pressing right now. Folding them together would let an
     * afternoon of iterating on wording eat a month of scheduled coverage, which is the failure the
     * preview's own daily budget already exists to prevent one level down.
     */
    public function previewQueriesThisMonth(Team $team): int
    {
        [$start, $end] = self::thisMonth();

        return (int) SignalPreview::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->sum('queries_count');
    }

    /**
     * Micro-units this team has spent reading full articles so far this calendar month (UTC).
     *
     * Counted as articles read × the per-fetch price, not from `signal_runs.cost_micros`: that
     * column is the run's *whole* cost, Serper and AI included, so a ceiling read off it would
     * shrink as the rest of the feature got busier. S12 settled this and the arithmetic is kept
     * here so the guard, the meter and the enricher all agree on one number.
     */
    public function articleFetchMicrosThisMonth(Team $team): int
    {
        [$start, $end] = self::thisMonth();

        $read = (int) Signal::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->whereNotNull('article_fetched_at')
            ->where('article_fetched_at', '>=', $start)
            ->where('article_fetched_at', '<', $end)
            ->count();

        return $read * max(0, (int) config('signals.article_fetch.cost_micros_per_fetch', 2_000));
    }

    /**
     * The calendar month in UTC, as a half-open pair — the window Billing's allowances run on
     * (ADR 0012), so a team reading their bill and a team meeting a ceiling mean the same period.
     *
     * @return array{Carbon, Carbon}
     */
    private static function thisMonth(): array
    {
        $start = Carbon::now('UTC')->startOfMonth();

        return [$start, $start->copy()->addMonth()];
    }
}
