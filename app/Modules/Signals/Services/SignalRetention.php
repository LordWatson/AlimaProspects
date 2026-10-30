<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Signals\Console\PruneSignalsCommand;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Http\Resources\SignalResource;
use App\Modules\Signals\Models\Signal;
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
 */
final class SignalRetention
{
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
