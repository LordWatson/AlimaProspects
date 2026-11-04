<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Signals\Actions\SyncPipelineWatchlistAction;
use App\Modules\Signals\Console\PruneSignalsCommand;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Enums\WatchlistSourceKind;
use App\Modules\Signals\Http\Resources\SignalResource;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalDefinitionSpend;
use App\Modules\Signals\Models\SignalPlaybookRun;
use App\Modules\Signals\Models\SignalPreview;
use App\Modules\Signals\Models\SignalRejection;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalSourceStat;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of how long Signals keeps a signal (S6, `compliance.md` §5a).
 *
 * It exists because two places need the answer and must not disagree: {@see PruneSignalsCommand}
 * deletes rows by it, and {@see SignalResource} tells the team "deletes in N days" by it. A screen
 * promising 90 days while the sweep used 30 would be worse than saying nothing.
 *
 * A **converted** signal has no window at all. It is the provenance of a real lead, so it lives as
 * long as that lead does — which is why {@see self::windowFor()} returns null for it and both
 * callers treat null as "never swept".
 *
 * **Two more tables since S11**, both swept by the same command. A {@see SignalRejection} is
 * remembered for as long as the run honours it and no longer, so the two numbers are one number
 * ({@see self::eligibleRejections()}). A {@see SignalCluster} has no clock of its own and is swept
 * when it has no signals left ({@see self::orphanedClusters()}) — which is what stops its
 * `subject_fingerprint` outliving every row it came from.
 *
 * **And five more since S16**, which is the phase that went looking for the tables nobody had given
 * a window to. They fall into the three groups `config/signals.php` names, and the group a table is
 * in is the thing to get right rather than the number:
 *
 * - *Data about a person or a company*: {@see SignalPlaybookRun} (180 days), on top of the cascade
 *   that already takes it with its signal.
 * - *Cost history*: {@see SignalRun} and {@see SignalDefinitionSpend} (400 days). Counts and money,
 *   naming nobody, and the answer to a question asked in arrears — but **finished runs only**, or
 *   the sweep would delete the row a queued job is about to claim.
 * - *Working state swept on a rolling window or on its subject*: {@see SignalPreview} (30 days, a
 *   hash and a cost), {@see SignalSourceStat} (180 days from `last_seen_at`, so an outlet the team
 *   still reads is never swept), and {@see SignalWatchlistEntry} — which has no clock at all and
 *   goes when the lead it mirrors does.
 *
 * Every query here is `withoutGlobalScopes()`: the sweep is a system job across every team, and it
 * is reachable only from the console.
 */
final class SignalRetention
{
    public function __construct(private readonly SignalRejectionLedger $rejections) {}

    /**
     * How many days a signal of this status is kept, or null when it is kept indefinitely.
     */
    public function windowFor(SignalStatus $status): ?int
    {
        return match ($status) {
            SignalStatus::Dismissed => max(1, (int) config('signals.retention.dismissed_days', 90)),
            SignalStatus::New => max(1, (int) config('signals.retention.unactioned_days', 180)),
            // The provenance of a lead someone is working.
            SignalStatus::Converted => null,
        };
    }

    /**
     * When a signal becomes eligible for deletion, or null when it never does.
     *
     * Measured from **`created_at`, not `published_at`**: the promise is about how long Alima has
     * held the record, and an article's own date is both outside our control and sometimes a
     * best-effort parse of "3 days ago" (S2). A signal created today about a year-old article has
     * been held for a day.
     *
     * Returns `CarbonInterface`, not `Illuminate\Support\Carbon`: the app runs on
     * `CarbonImmutable` (AppServiceProvider), which is a *sibling* of that class rather than a
     * subclass, so the narrower type is a runtime TypeError PHPStan can't see.
     */
    public function deletesAt(Signal $signal): ?CarbonInterface
    {
        $window = $this->windowFor($signal->status);

        if ($window === null || $signal->created_at === null) {
            return null;
        }

        return $signal->created_at->copy()->addDays($window);
    }

    /**
     * Whole days until the sweep will delete this signal, or null when it won't.
     *
     * Never negative: the sweep runs nightly, so a row can legitimately sit past its window for a
     * few hours, and "deletes in -2 days" tells the team nothing.
     */
    public function deletesInDays(Signal $signal): ?int
    {
        $deletesAt = $this->deletesAt($signal);

        if ($deletesAt === null) {
            return null;
        }

        return max(0, (int) ceil(now()->diffInDays($deletesAt, absolute: false)));
    }

    /**
     * The rows of `$status` that are past their window, **without the tenant scope**: the sweep is
     * a system job across every team, which is why it is reachable only from the console.
     *
     * Returns null for a status that is never swept, so a caller can't accidentally build a query
     * that would delete converted signals.
     *
     * @return Builder<Signal>|null
     */
    public function eligible(SignalStatus $status): ?Builder
    {
        $window = $this->windowFor($status);

        if ($window === null) {
            return null;
        }

        return Signal::query()
            ->withoutGlobalScopes()
            ->where('status', $status)
            ->where('created_at', '<', now()->subDays($window));
    }

    /**
     * The rejection memories past their window, **without the tenant scope** — the same system-job
     * reasoning as {@see self::eligible()} (S11).
     *
     * One window for honouring a rejection and for keeping the row, read from
     * {@see SignalRejectionLedger::window()} rather than from config here: a sweep that deleted a
     * row the run still trusts would make the run pay again, and one that kept a row the run has
     * stopped trusting would be dead weight. Two copies of that number is how they drift apart.
     *
     * @return Builder<SignalRejection>
     */
    public function eligibleRejections(): Builder
    {
        return SignalRejection::query()
            ->withoutGlobalScopes()
            ->where('last_seen_at', '<', now()->subDays($this->rejections->window()));
    }

    /**
     * Clusters with no signals left in them, **without the tenant scope**.
     *
     * A cluster is not swept on its own clock — it has no window, because it is not a record about
     * a person or an article, it is the fact that several articles were one event. It is swept when
     * the last of those articles goes, which is what keeps `subject_fingerprint` from outliving
     * every row it was derived from.
     *
     * **An hour's grace, and it is there to lose a race.** A run can find or create an empty
     * cluster and then spend a moment on `insertOrIgnore`; a sweep landing in that gap would delete
     * the row the insert is about to reference, and MySQL turns the resulting foreign-key violation
     * in an `INSERT IGNORE` into a *warning* — so the signals would be dropped silently, which is
     * the worst way for this to fail (found in review). A cluster nobody has touched for an hour is
     * not one a run is mid-way through writing.
     *
     * @return Builder<SignalCluster>
     */
    public function orphanedClusters(): Builder
    {
        return SignalCluster::query()
            ->withoutGlobalScopes()
            ->where('updated_at', '<', now()->subHour())
            // The relation subquery is scoped **explicitly too**. A relation applies the *related*
            // model's global scopes, so if this sweep were ever reached inside a team context the
            // `Signal` team scope would narrow the subquery and every other tenant's clusters
            // would look empty — and be deleted. It reads as a no-op today precisely because the
            // command runs with no context; that is not a thing to leave resting on.
            ->whereDoesntHave('signals', static fn (Builder $signals) => $signals->withoutGlobalScopes());
    }

    /**
     * Clusters that still have members but have lost their primary, **without the tenant scope**.
     *
     * Retention deletes per *signal*, so it can take the article a cluster was being shown as and
     * leave the rest; the FK nulls `primary_signal_id`. `Signal::representatives()` is deliberately
     * tolerant of that — it shows every member rather than none — so the failure is a feed with
     * seven cards where there should be one, which somebody notices. This is what puts it right:
     * {@see SignalClusterer::reconcile()} picks the next-best article and the card becomes one card
     * again. Found in review; the sweep used to leave it for ever.
     *
     * @return Builder<SignalCluster>
     */
    public function clustersNeedingAPrimary(): Builder
    {
        return SignalCluster::query()
            ->withoutGlobalScopes()
            ->whereNull('primary_signal_id')
            ->whereHas('signals', static fn (Builder $signals) => $signals->withoutGlobalScopes());
    }

    /**
     * The preview ledger rows past their window (S16).
     *
     * A preview row holds a draft **hash**, three counts and a cost — no headline, no name and no
     * result, because the preview deliberately persists nothing into `signals`. So the window is
     * about keeping the table small and keeping an answer to "where did our preview budget go on
     * Tuesday", not about minimisation.
     *
     * Swept on `created_at`, which for this table is also when the money was spent.
     *
     * @return Builder<SignalPreview>
     */
    public function eligiblePreviews(): Builder
    {
        return SignalPreview::query()
            ->withoutGlobalScopes()
            ->where('created_at', '<', now()->subDays($this->days('preview_days', 30)));
    }

    /**
     * The finished runs past their window (S16).
     *
     * **`finished_at` and not `created_at`, and not null.** A queued or running row is live state:
     * `RunSignalWatchAction::claimOrCreateRun()` adopts a non-terminal run for the watch, so
     * deleting one by age would take the row a worker is about to claim — and a run that has been
     * sitting queued for four hundred days is a sign of a stopped queue, which is somebody's
     * problem to look at rather than the sweep's to tidy away.
     *
     * `signals.signal_run_id` is `nullOnDelete`, so a signal whose run is swept keeps its row and
     * loses the link. That is the right way round: the signal is the record about a company and the
     * run is the cost of having found it.
     *
     * @return Builder<SignalRun>
     */
    public function eligibleRuns(): Builder
    {
        return SignalRun::query()
            ->withoutGlobalScopes()
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', now()->subDays($this->days('run_days', 400)));
    }

    /**
     * The per-type spend rows past their window (S16).
     *
     * Swept on `day`, the UTC date the spend was attributed to, rather than on `updated_at`: a
     * row's timestamps move every time a run that day adds to it, so `updated_at` would be the
     * day it was last written and `day` is the day being reported on. The same window as
     * {@see self::eligibleRuns()}, because they are two views of one cost history and keeping one
     * without the other would make the ROI card and the run list disagree about a month.
     *
     * @return Builder<SignalDefinitionSpend>
     */
    public function eligibleSpend(): Builder
    {
        return SignalDefinitionSpend::query()
            ->withoutGlobalScopes()
            // **`where`, not `whereDate`.** `day` is already a `date` column, so `whereDate` only
            // wraps it in `date(day)` — which costs nothing in correctness and everything in plan:
            // a function on the column makes the index on it unusable, and this sweep re-runs its
            // query once per chunk. Comparing a date column to a date string is exactly what the
            // index is for.
            ->where('day', '<', now()->subDays($this->days('spend_days', 400))->toDateString());
    }

    /**
     * The playbook run log rows past their window (S16).
     *
     * **A second clock on a row that already has one.** `signal_playbook_runs.signal_id` is
     * `cascadeOnDelete`, so the log already dies with its signal — which covers every signal the
     * sweep takes. What it does not cover is a **converted** signal, which is never swept at all:
     * its log would otherwise be kept for as long as the lead, and the record of what an automation
     * did to a prospect is data about that prospect rather than provenance the team needs for ever.
     * So this window is what makes the promise true in the one case the cascade cannot.
     *
     * @return Builder<SignalPlaybookRun>
     */
    public function eligiblePlaybookRuns(): Builder
    {
        return SignalPlaybookRun::query()
            ->withoutGlobalScopes()
            ->where('created_at', '<', now()->subDays($this->days('playbook_run_days', 180)));
    }

    /**
     * The per-outlet tallies nobody has seen for a while (S16).
     *
     * **Rolling, on `last_seen_at`.** An outlet the team is still shown every week is never swept
     * however old its row is, and one that has stopped appearing stops voting in the scorer's
     * source-authority component. Swept on age instead, the row for a team's best outlet would be
     * deleted on its birthday and the authority it had earned would start again from nothing.
     *
     * A null `last_seen_at` is treated as eligible only once the row itself is old, because the
     * column is nullable for rows written before S12 finished and "never seen" and "seen before we
     * counted" are not the same claim.
     *
     * @return Builder<SignalSourceStat>
     */
    public function eligibleSourceStats(): Builder
    {
        $cutoff = now()->subDays($this->days('source_stat_days', 180));

        return SignalSourceStat::query()
            ->withoutGlobalScopes()
            ->where(static fn (Builder $query) => $query
                ->where('last_seen_at', '<', $cutoff)
                ->orWhere(static fn (Builder $nested) => $nested
                    ->whereNull('last_seen_at')
                    ->where('created_at', '<', $cutoff)));
    }

    /**
     * Watchlist entries whose lead has gone (S16).
     *
     * **No clock of its own: an entry lives as long as the lead it mirrors.** A synced list's
     * entries are an *output* of the team's pipeline, `lead_id` is `nullOnDelete`, and an entry
     * left behind by a deleted lead keeps its company name on the list, keeps being paid for on
     * every run and keeps counting against the cap.
     *
     * {@see SyncPipelineWatchlistAction} already clears these on the nightly sync — but only for a
     * list it is allowed to touch, and it skips a **paused** one on purpose ("stop searching" is
     * not "forget what I told you"). A paused list is exactly where an orphan would sit for ever,
     * so this is the half that covers it.
     *
     * A manual list is left alone: a null `lead_id` there is the normal case — a company somebody
     * typed that is not a lead yet — rather than a lead that has gone.
     *
     * @return Builder<SignalWatchlistEntry>
     */
    public function orphanedWatchlistEntries(): Builder
    {
        return SignalWatchlistEntry::query()
            ->withoutGlobalScopes()
            ->whereNull('lead_id')
            // The relation is scoped explicitly as well, for the reason `orphanedClusters()` gives:
            // a relation applies the *related* model's global scopes, so this reads as a no-op only
            // because the command runs with no team in context.
            ->whereHas('watchlist', static fn (Builder $lists) => $lists
                ->withoutGlobalScopes()
                ->whereIn('source_kind', WatchlistSourceKind::syncedValues()));
    }

    /**
     * One retention window in days, floored at one so a misconfigured zero cannot mean "delete
     * everything immediately".
     */
    private function days(string $key, int $default): int
    {
        return max(1, (int) config('signals.retention.'.$key, $default));
    }

    /**
     * The statuses the sweep considers, in the order it sweeps them.
     *
     * @return list<SignalStatus>
     */
    public function sweptStatuses(): array
    {
        return array_values(array_filter(
            SignalStatus::cases(),
            fn (SignalStatus $status): bool => $this->windowFor($status) !== null,
        ));
    }
}
