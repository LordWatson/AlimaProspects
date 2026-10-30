<?php

declare(strict_types=1);

use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Enums\TrendGranularity;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Reporting\Support\TrendBuckets;
use Illuminate\Support\Facades\Date;

it('cuts weeks in the team timezone, oldest first', function (): void {
    // A Wednesday. The current week opens on Monday the 28th.
    Date::setTestNow('2026-10-30 09:00:00');

    $buckets = TrendBuckets::lastPeriods(TrendGranularity::Week, 4, 'Europe/London');

    expect($buckets->count())->toBe(4)
        ->and(array_column($buckets->buckets, 'starts_on'))->toBe([
            '2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26',
        ]);
});

it('cuts a Dubai team\'s weeks in Dubai, not on the server', function (): void {
    // 23:30 UTC on Sunday is already Monday in Dubai (+04), so the two teams are in different
    // weeks at the same instant — which is the whole reason the cut is timezone-aware.
    Date::setTestNow('2026-10-25 23:30:00');

    $dubai = TrendBuckets::lastPeriods(TrendGranularity::Week, 1, 'Asia/Dubai');
    $london = TrendBuckets::lastPeriods(TrendGranularity::Week, 1, 'Europe/London');

    expect($dubai->buckets[0]['starts_on'])->toBe('2026-10-26')
        ->and($london->buckets[0]['starts_on'])->toBe('2026-10-19');
});

it('bounds the window at one period and at the alias ceiling', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    expect(TrendBuckets::lastPeriods(TrendGranularity::Week, 0, 'UTC')->count())->toBe(1)
        ->and(TrendBuckets::lastPeriods(TrendGranularity::Week, -5, 'UTC')->count())->toBe(1)
        // One query can only carry as many case columns as there are literal aliases.
        ->and(TrendBuckets::lastPeriods(TrendGranularity::Week, 500, 'UTC')->count())->toBe(52);
});

it('pairs one aliased case column with two bound instants per bucket', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    $buckets = TrendBuckets::lastPeriods(TrendGranularity::Week, 2, 'Europe/London');
    $select = $buckets->countSelect('leads.created_at');

    expect($select)->toBe(
        'sum(case when leads.created_at >= ? and leads.created_at < ? then 1 else 0 end) as b00, '
        .'sum(case when leads.created_at >= ? and leads.created_at < ? then 1 else 0 end) as b01',
    )
        ->and($buckets->bindings())->toHaveCount(4)
        // Stored UTC, displayed in the team timezone (CLAUDE.md §2) — BST is +01 on this date.
        ->and($buckets->bindings()[0])->toBe('2026-10-18 23:00:00');
});

it('reads a result row back in bucket order and treats a missing column as zero', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    $buckets = TrendBuckets::lastPeriods(TrendGranularity::Week, 3, 'UTC');

    expect($buckets->readRow(['b00' => '4', 'b02' => 9]))->toBe([4, 0, 9]);
});

it('cuts a short window into days and a long one into months', function (): void {
    Date::setTestNow('2026-10-30 12:00:00');

    $week = TrendBuckets::forRange(ReportRange::Last7Days->resolve('UTC'));
    // Two years: past the 364-day weekly ceiling, so the bars become months.
    $long = TrendBuckets::forRange(DateRange::for(ReportRange::Custom, 'UTC', '2025-01-15', '2026-10-30'));

    expect($week->granularity)->toBe(TrendGranularity::Day)
        // Seven days back from midday lands at midday on the 23rd, so the window touches eight
        // calendar days — the first and last of them partial.
        ->and($week->count())->toBe(8)
        ->and($week->buckets[0]['starts_on'])->toBe('2026-10-23')
        ->and($long->granularity)->toBe(TrendGranularity::Month)
        ->and($long->buckets[0]['starts_on'])->toBe('2025-01-01')
        // January 2025 through October 2026 inclusive.
        ->and($long->count())->toBe(22);
});

it('keeps a year-long window weekly', function (): void {
    // 364 days is the alias ceiling read backwards: one more bucket than the query can carry would
    // have to be dropped, so the window steps up to months instead.
    Date::setTestNow('2026-10-30 12:00:00');

    $year = TrendBuckets::forRange(ReportRange::ThisYear->resolve('UTC'));

    expect($year->granularity)->toBe(TrendGranularity::Week)
        ->and($year->buckets[0]['starts_on'])->toBe('2025-12-29')
        ->and($year->count())->toBeLessThanOrEqual(52);
});

it('clamps the first and last bucket to the window', function (): void {
    // The window opens mid-week, so the first bucket's *period* is the Monday before while the
    // rows it counts must start where the filter did — otherwise the chart's total disagrees with
    // every other figure on the dashboard.
    Date::setTestNow('2026-10-30 12:00:00');

    $range = DateRange::for(ReportRange::Custom, 'UTC', '2026-10-07', '2026-10-20');
    $buckets = TrendBuckets::forRange($range);

    expect($buckets->granularity)->toBe(TrendGranularity::Day)
        ->and($buckets->buckets[0]['starts_on'])->toBe('2026-10-07')
        ->and($buckets->from()->toDateTimeString())->toBe('2026-10-07 00:00:00')
        // The window is inclusive of the 20th, so it closes at the start of the 21st.
        ->and($buckets->buckets[$buckets->count() - 1]['end']->toDateTimeString())
        ->toBe('2026-10-21 00:00:00');
});

it('falls back to recent weeks when the window is open-ended', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    $buckets = TrendBuckets::forRange(DateRange::allTime('UTC'), 6);

    expect($buckets->granularity)->toBe(TrendGranularity::Week)
        ->and($buckets->count())->toBe(6)
        ->and($buckets->buckets[5]['starts_on'])->toBe('2026-10-26');
});

it('steps monthly buckets back without skipping February', function (): void {
    // 31 March minus a month is "30 February" to naive arithmetic, which overflows into March and
    // loses February entirely.
    Date::setTestNow('2026-03-31 09:00:00');

    $buckets = TrendBuckets::lastPeriods(TrendGranularity::Month, 3, 'UTC');

    expect(array_column($buckets->buckets, 'starts_on'))
        ->toBe(['2026-01-01', '2026-02-01', '2026-03-01']);
});
