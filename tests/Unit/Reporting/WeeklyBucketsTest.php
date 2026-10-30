<?php

declare(strict_types=1);

use App\Modules\Reporting\Support\WeeklyBuckets;
use Illuminate\Support\Facades\Date;

it('cuts weeks in the team timezone, oldest first', function (): void {
    // A Wednesday. The current week opens on Monday the 28th.
    Date::setTestNow('2026-10-30 09:00:00');

    $buckets = WeeklyBuckets::lastWeeks(4, 'Europe/London');

    expect($buckets->count())->toBe(4)
        ->and(array_column($buckets->buckets, 'starts_on'))->toBe([
            '2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26',
        ]);
});

it('cuts a Dubai team\'s weeks in Dubai, not on the server', function (): void {
    // 23:30 UTC on Sunday is already Monday in Dubai (+04), so the two teams are in different
    // weeks at the same instant — which is the whole reason the cut is timezone-aware.
    Date::setTestNow('2026-10-25 23:30:00');

    expect(WeeklyBuckets::lastWeeks(1, 'Asia/Dubai')->buckets[0]['starts_on'])->toBe('2026-10-26')
        ->and(WeeklyBuckets::lastWeeks(1, 'Europe/London')->buckets[0]['starts_on'])->toBe('2026-10-19');
});

it('bounds the window at one week and at the alias ceiling', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    expect(WeeklyBuckets::lastWeeks(0, 'UTC')->count())->toBe(1)
        ->and(WeeklyBuckets::lastWeeks(-5, 'UTC')->count())->toBe(1)
        // One query can only carry as many case columns as there are literal aliases.
        ->and(WeeklyBuckets::lastWeeks(500, 'UTC')->count())->toBe(52);
});

it('pairs one aliased case column with two bound instants per bucket', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    $buckets = WeeklyBuckets::lastWeeks(2, 'Europe/London');
    $select = $buckets->countSelect('leads.created_at');

    expect($select)->toBe(
        'sum(case when leads.created_at >= ? and leads.created_at < ? then 1 else 0 end) as w00, '
        .'sum(case when leads.created_at >= ? and leads.created_at < ? then 1 else 0 end) as w01',
    )
        ->and($buckets->bindings())->toHaveCount(4)
        // Stored UTC, displayed in the team timezone (CLAUDE.md §2) — BST is +01 on this date.
        ->and($buckets->bindings()[0])->toBe('2026-10-18 23:00:00');
});

it('reads a result row back in week order and treats a missing column as zero', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    $buckets = WeeklyBuckets::lastWeeks(3, 'UTC');

    expect($buckets->readRow(['w00' => '4', 'w02' => 9]))->toBe([4, 0, 9]);
});
