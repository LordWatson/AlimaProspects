<?php

declare(strict_types=1);

use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Enums\TrendGranularity;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Support\Facades\Date;

it('resolves a rolling window against the team clock and never past now', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    $range = ReportRange::Last30Days->resolve('Europe/London');

    expect($range->isBounded())->toBeTrue()
        ->and($range->from?->toDateTimeString())->toBe('2026-09-30 09:00:00')
        ->and($range->to?->toDateTimeString())->toBe('2026-10-30 09:00:00')
        // Thirty days even though the clocks go back inside the window and it is really thirty
        // days and an hour — see `days()`.
        ->and($range->days())->toBe(30);
});

it('opens a calendar month on the 1st in the team timezone', function (): void {
    // 21:30 UTC on 31 October is already 1 November in Dubai (+04): the two teams are in different
    // months at the same instant.
    Date::setTestNow('2026-10-31 21:30:00');

    expect(ReportRange::ThisMonth->resolve('Asia/Dubai')->fromDate())->toBe('2026-11-01')
        ->and(ReportRange::ThisMonth->resolve('Europe/London')->fromDate())->toBe('2026-10-01');
});

it('closes last month at this month\'s start rather than at now', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    $range = ReportRange::LastMonth->resolve('UTC');

    expect($range->fromDate())->toBe('2026-09-01')
        // `toDate()` reports the last day the window covers, not its exclusive bound.
        ->and($range->toDate())->toBe('2026-09-30');
});

it('has no window before all time, so no delta', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    $range = ReportRange::AllTime->resolve('UTC');

    expect($range->isBounded())->toBeFalse()
        ->and($range->days())->toBeNull()
        ->and($range->previous())->toBeNull()
        ->and($range->preset->deltaLabel())->toBeNull();
});

it('compares a part-finished period against the same length before it', function (): void {
    // "This month so far" against the whole of last month would not be a comparison. The previous
    // window is equal in length and butts up against where this one opens.
    Date::setTestNow('2026-10-15 00:00:00');

    $range = ReportRange::ThisMonth->resolve('UTC');
    $previous = $range->previous();

    expect($range->from?->toDateTimeString())->toBe('2026-10-01 00:00:00')
        ->and($previous?->from->toDateTimeString())->toBe('2026-09-17 00:00:00')
        ->and($previous?->to->toDateTimeString())->toBe('2026-10-01 00:00:00');
});

it('reads a custom range as whole days, inclusive of the end date', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    $range = DateRange::for(ReportRange::Custom, 'UTC', '2026-10-01', '2026-10-07');

    expect($range->from?->toDateTimeString())->toBe('2026-10-01 00:00:00')
        // Exclusive bound is the start of the 8th, so the 7th is included in full.
        ->and($range->to?->toDateTimeString())->toBe('2026-10-08 00:00:00')
        ->and($range->toDate())->toBe('2026-10-07');
});

it('falls back to all time for a custom range that is missing, malformed or backwards', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    // A stale bookmark should show a dashboard, not a stack trace.
    expect(DateRange::for(ReportRange::Custom, 'UTC', '2026-10-01', null)->isBounded())->toBeFalse()
        ->and(DateRange::for(ReportRange::Custom, 'UTC', 'yesterday', '2026-10-07')->isBounded())->toBeFalse()
        // Well-formed and still not a date.
        ->and(DateRange::for(ReportRange::Custom, 'UTC', '2026-02-31', '2026-10-07')->isBounded())->toBeFalse()
        ->and(DateRange::for(ReportRange::Custom, 'UTC', '2026-10-07', '2026-10-01')->isBounded())->toBeFalse();
});

it('clamps a custom range to the ceiling and never past now', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    // Six years asked for, two years and a day given back — clamped from the *end*, so the window
    // a member gets is the recent part of what they asked for rather than the oldest.
    $range = DateRange::for(ReportRange::Custom, 'UTC', '2020-01-01', '2026-10-20');

    expect($range->fromDate())->toBe('2024-10-20')
        ->and($range->toDate())->toBe('2026-10-20')
        ->and($range->days())->toBe(DateRange::MAX_DAYS);

    // An end date in the future is honoured as a filter but the window still stops at now: there
    // are no rows after now, and a window that ran on would make "the period before" wrong.
    $future = DateRange::for(ReportRange::Custom, 'UTC', '2026-10-29', '2026-12-31');

    expect($future->to?->toDateTimeString())->toBe('2026-10-30 09:00:00');
});

it('picks the bucket width from the length of the window', function (): void {
    expect(TrendGranularity::for(7))->toBe(TrendGranularity::Day)
        ->and(TrendGranularity::for(35))->toBe(TrendGranularity::Day)
        ->and(TrendGranularity::for(36))->toBe(TrendGranularity::Week)
        // 358 = (52 × 7) − 6: the largest window that still fits in 52 weekly buckets once the
        // six days a mid-week start borrows from the week before are allowed for.
        ->and(TrendGranularity::for(358))->toBe(TrendGranularity::Week)
        ->and(TrendGranularity::for(359))->toBe(TrendGranularity::Month)
        // No length to measure: the trend shows its most recent weeks.
        ->and(TrendGranularity::for(null))->toBe(TrendGranularity::Week);
});
