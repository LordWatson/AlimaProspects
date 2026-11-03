<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Signals\Console\PruneSignalsCommand;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Http\Resources\SignalResource;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalRejection;
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
