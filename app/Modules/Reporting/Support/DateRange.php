<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use App\Modules\Reporting\Actions\BuildLeadFunnelAction;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Enums\TrendGranularity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * A resolved reporting window: a **half-open** interval `[from, to)` of UTC instants, plus the
 * preset it came from and the timezone it was cut in.
 *
 * Half-open on purpose. A closed interval would either double-count a row that lands exactly on
 * midnight or lose it, depending on which end was `<=`; `>= from and < to` is the one shape where
 * consecutive windows tile the timeline without a seam — which is what makes
 * {@see self::previous()} a true like-for-like rather than an off-by-one.
 *
 * Cut in the **team's** timezone and compared as UTC instants, because timestamps are stored UTC
 * and displayed in the team timezone (CLAUDE.md §2). `to` never runs past *now*: a window that
 * includes the rest of today would compare a part-finished period against a whole one and call the
 * difference a trend.
 *
 * ## What the window selects
 *
 * One rule, because a dashboard whose charts disagree is worse than no dashboard. The window picks
 * a **cohort of leads** — those sourced inside it — and every lead-derived figure reports on that
 * cohort: the funnel, the trend, the leads and strong-fit tiles, the fit mix, the source and
 * pipeline breakdowns, and ICP performance. So "contacted" means *of the leads we sourced in this
 * window, how many have we approached* — which is what makes the funnel's steps comparable with
 * one another at all. (Which of them actually nest is a separate question, answered in
 * {@see BuildLeadFunnelAction}.)
 *
 * Reports that are about activity rather than about leads are scoped by their own event time
 * instead — AI usage by when the call was made, team activity by when the member did the thing,
 * outreach performance by when the approach happened. Each of those says so in its own docblock.
 */
final readonly class DateRange
{
    /**
     * Longest window a custom date range may ask for, in days. Two years and a day, so that "this
     * year against last year" fits.
     *
     * A constant rather than a config key on purpose: it is a product decision about what the
     * dashboard is for, not something an environment should differ on — and a deployment that
     * raised it would be asking the same aggregations to scan a table nobody has indexed for it.
     */
    public const int MAX_DAYS = 731;

    private function __construct(
        public ReportRange $preset,
        /** Inclusive lower bound; null for an open-ended window (all time). */
        public ?CarbonImmutable $from,
        /** Exclusive upper bound; null for an open-ended window. */
        public ?CarbonImmutable $to,
        public string $timezone,
    ) {}

    /**
     * Resolve a preset against the team's clock.
     *
     * `$from`/`$to` are `Y-m-d` dates in the team's timezone and are read only by
     * {@see ReportRange::Custom}. A custom range missing either end, or with the ends the wrong way
     * round, resolves to all time rather than erroring: a stale bookmark should show a dashboard,
     * not a stack trace. The window is capped at {@see self::MAX_DAYS} so one URL cannot ask for an
     * unbounded scan.
     */
    public static function for(
        ReportRange $preset,
        string $timezone,
        ?string $from = null,
        ?string $to = null,
    ): self {
        // `toImmutable()` is not decoration: the arithmetic below must not rewrite the same instant
        // on each call. The app configures the Date facade immutable (AppServiceProvider), but this
        // class must not depend on that being true.
        $now = Date::now($timezone)->toImmutable();

        return match ($preset) {
            ReportRange::AllTime => new self($preset, null, null, $timezone),
            ReportRange::Last7Days => self::rolling($preset, $now, $timezone, 7),
            ReportRange::Last30Days => self::rolling($preset, $now, $timezone, 30),
            ReportRange::Last90Days => self::rolling($preset, $now, $timezone, 90),
            ReportRange::ThisMonth => new self($preset, $now->startOfMonth(), $now, $timezone),
            // The one complete calendar period on the list, so its upper bound is the period's own
            // end rather than now.
            ReportRange::LastMonth => new self(
                $preset,
                $now->subMonthNoOverflow()->startOfMonth(),
                $now->startOfMonth(),
                $timezone,
            ),
            ReportRange::ThisQuarter => new self($preset, $now->startOfQuarter(), $now, $timezone),
            ReportRange::ThisYear => new self($preset, $now->startOfYear(), $now, $timezone),
            ReportRange::Custom => self::custom($now, $timezone, $from, $to),
        };
    }

    public static function allTime(string $timezone): self
    {
        return new self(ReportRange::AllTime, null, null, $timezone);
    }

    /** Whether anything is actually narrowing the figures. */
    public function isBounded(): bool
    {
        return $this->from !== null;
    }

    /**
     * How many whole days the window spans, or null when it is open-ended. Used to pick the trend's
     * granularity and to size {@see self::previous()}.
     */
    public function days(): ?int
    {
        if ($this->from === null || $this->to === null) {
            return null;
        }

        // Rounded, not ceilinged, and never below one.
        //
        // Ceiling would over-report any window that crosses a clock change: 30 September to 30
        // October in London is thirty days on the wall clock and thirty days *and an hour* of real
        // elapsed time, because the clocks go back in between. That extra hour must not turn a
        // 30-day window into a 31-day one and quietly move the trend's bucket width.
        return max(1, (int) round($this->from->diffInSeconds($this->to, absolute: true) / 86_400));
    }

    /**
     * The window of equal length immediately before this one, for a KPI's delta — or null when
     * there is nothing to compare against (all time has no "before").
     *
     * Equal length and butted up against `from`, so "this month so far" is compared with the same
     * number of days ending where this window opens, not with the whole of last month.
     */
    public function previous(): ?self
    {
        if ($this->from === null || $this->to === null) {
            return null;
        }

        $length = (int) round($this->from->diffInSeconds($this->to, absolute: true));

        return new self(
            $this->preset,
            $this->from->subSeconds($length),
            $this->from,
            $this->timezone,
        );
    }

    public function granularity(): TrendGranularity
    {
        return TrendGranularity::for($this->days());
    }

    /**
     * Narrow a query to the window. A no-op for an open-ended one, which is what keeps every
     * aggregation Action free of `if ($range->isBounded())`.
     *
     * Qualify the column (`leads.created_at`): several of these queries join, and an ambiguous
     * column name is a 500 nobody sees until production.
     *
     * **The bounds are converted to UTC here, and that is not optional.** `from` and `to` are
     * Carbon instances in the *team's* timezone, and Laravel binds a `DateTimeInterface` by calling
     * `->format()` on it with no timezone conversion at all (`Connection::prepareBindings`). Handing
     * it a Dubai instant would therefore compare 19:48 Dubai wall-clock against UTC columns and
     * shift every figure on the dashboard four hours late at both ends — silently, and only for
     * teams outside UTC, which is exactly the bug a UTC-only test suite cannot see.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $column
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, string $column): Builder
    {
        if ($this->from !== null) {
            $query->where($column, '>=', $this->from->utc());
        }

        if ($this->to !== null) {
            $query->where($column, '<', $this->to->utc());
        }

        return $query;
    }

    /** The lower bound as an ISO-8601 date in the team's timezone, for the screen's subtitle. */
    public function fromDate(): ?string
    {
        return $this->from?->toDateString();
    }

    /**
     * The upper bound as an ISO-8601 date in the team's timezone — the **last day the window
     * covers**, not the exclusive bound, because that is the date a member expects to read back
     * out of the filter they set.
     */
    public function toDate(): ?string
    {
        return $this->to?->subSecond()->toDateString();
    }

    private static function rolling(
        ReportRange $preset,
        CarbonImmutable $now,
        string $timezone,
        int $days,
    ): self {
        return new self($preset, $now->subDays($days), $now, $timezone);
    }

    private static function custom(
        CarbonImmutable $now,
        string $timezone,
        ?string $from,
        ?string $to,
    ): self {
        $start = self::day($from, $timezone);
        $end = self::day($to, $timezone);

        if ($start === null || $end === null || $start->greaterThan($end)) {
            return self::allTime($timezone);
        }

        // `MAX_DAYS - 1`, because both ends are days the member asked to see: a window from the
        // 1st to the 1st is one day, not nought.
        if ($start->diffInDays($end, absolute: true) >= self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        // The `to` date is inclusive to the member who typed it, so the exclusive bound is the
        // start of the next day — and never later than now.
        $exclusiveEnd = $end->addDay();
        $exclusiveEnd = $exclusiveEnd->greaterThan($now) ? $now : $exclusiveEnd;

        // A window wholly in the future collapses once the end is capped at now, leaving `from`
        // after `to` — an inverted interval that would match nothing while `days()` (absolute)
        // still reported a length, and that `TrendBuckets` would try to cut into bars. All time is
        // the honest answer to a filter that cannot contain any data.
        if (! $start->lessThan($exclusiveEnd)) {
            return self::allTime($timezone);
        }

        return new self(ReportRange::Custom, $start, $exclusiveEnd, $timezone);
    }

    /**
     * A `Y-m-d` string as the start of that day in the team's timezone, or null when it isn't one.
     *
     * The round-trip comparison is the point: `createFromFormat` happily reads "2026-02-31" and
     * hands back 3 March, so a well-formed date that is not a date would otherwise resolve to a
     * window three days off the one the caller asked for. Formatting the result back and requiring
     * it to match is the only check that catches that.
     */
    private static function day(?string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            // `!` resets the time fields, so the result is midnight rather than "now, on that day".
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable) {
            return null;
        }

        return $date === null || $date->format('Y-m-d') !== $value ? null : $date;
    }
}
