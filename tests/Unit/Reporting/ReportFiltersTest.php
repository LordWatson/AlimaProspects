<?php

declare(strict_types=1);

use App\Modules\Reporting\Data\ReportFilters;
use App\Modules\Reporting\Enums\ReportRange;
use Illuminate\Support\Facades\Date;

it('defaults to all time', function (): void {
    expect(ReportFilters::fromArray([])->range)->toBe(ReportRange::AllTime);
});

it('drops an unknown range rather than failing', function (): void {
    // A bookmark from an older release should still open the dashboard.
    expect(ReportFilters::fromArray(['range' => 'last_fortnight'])->range)
        ->toBe(ReportRange::AllTime);
});

it('reads a bare date pair as a custom range', function (): void {
    $filters = ReportFilters::fromArray(['from' => '2026-10-01', 'to' => '2026-10-07']);

    expect($filters->range)->toBe(ReportRange::Custom)
        ->and($filters->from)->toBe('2026-10-01')
        ->and($filters->to)->toBe('2026-10-07');
});

it('discards dates that do not belong to a custom range', function (): void {
    // Carrying them would put them back in the URL and in the form, where they would look active.
    $filters = ReportFilters::fromArray([
        'range' => 'last_30_days',
        'from' => '2026-10-01',
        'to' => '2026-10-07',
    ]);

    expect($filters->from)->toBeNull()
        ->and($filters->to)->toBeNull()
        ->and($filters->toParams())->toBe(['range' => 'last_30_days']);
});

it('ignores a malformed date', function (): void {
    $filters = ReportFilters::fromArray(['from' => '01/10/2026', 'to' => '2026-10-07']);

    expect($filters->range)->toBe(ReportRange::AllTime)
        ->and($filters->from)->toBeNull();
});

it('leaves the default range out of the URL', function (): void {
    expect(ReportFilters::fromArray([])->toParams())->toBe([])
        ->and(ReportFilters::fromArray(['from' => '2026-10-01', 'to' => '2026-10-07'])->toParams())
        ->toBe(['range' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-07']);
});

it('fills the date inputs with the window actually being shown', function (): void {
    Date::setTestNow('2026-10-30 09:00:00');

    // A member who opens the custom pair after picking "last 7 days" should find it already
    // holding those seven days, not today twice.
    expect(ReportFilters::fromArray(['range' => 'last_7_days'])->toFormState('UTC'))->toBe([
        'range' => 'last_7_days',
        'from' => '2026-10-23',
        'to' => '2026-10-30',
    ]);

    expect(ReportFilters::fromArray([])->toFormState('UTC'))->toBe([
        'range' => 'all_time',
        'from' => '2026-10-30',
        'to' => '2026-10-30',
    ]);
});
