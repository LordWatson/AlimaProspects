<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Enums\TrendGranularity;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Reporting\Support\TrendBuckets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A lead whose `created_at` is a given **UTC** instant, which is how the column is stored.
 */
function leadCreatedAt(Team $team, string $utc): Lead
{
    return app(TeamContext::class)->run($team, function () use ($team, $utc): Lead {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'dedupe_key' => 'window:'.Str::random(12),
        ]);

        $lead->forceFill(['created_at' => $utc])->saveQuietly();

        return $lead->refresh();
    });
}

it('bounds the window in UTC, not in the team\'s wall-clock time', function (): void {
    // The bug this guards: `from`/`to` are Carbon instances in the team's timezone, and Laravel
    // binds a date by calling `->format()` on it with no conversion. A Dubai instant handed
    // straight to the query compares 19:48 Dubai against UTC columns and shifts every figure on
    // the dashboard four hours late at both ends — invisible to a UTC-only suite.
    Date::setTestNow('2026-10-30 12:00:00');

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $team->forceFill(['timezone' => 'Asia/Dubai'])->save();

    // "Now" is 16:00 Dubai, so a seven-day window is [23rd 16:00 Dubai, 30th 16:00 Dubai) —
    // which is [23rd 12:00 UTC, 30th 12:00 UTC) in the column's own terms. Bind those bounds
    // unconverted and the query reads them as 16:00 UTC instead, letting in four extra hours at
    // the bottom: the 11:00 lead below would be counted.
    leadCreatedAt($team, '2026-10-23 11:00:00');  // before the window opens
    leadCreatedAt($team, '2026-10-23 13:00:00');  // inside
    leadCreatedAt($team, '2026-10-29 23:00:00');  // inside

    $this->actingAs($user)
        ->get(route('dashboard', ['range' => 'last_7_days']))
        ->assertOk()
        // Unconverted bounds would read 16:00–16:00 Dubai as UTC and count the 11:00 lead too.
        ->assertInertia(fn ($page) => $page->where('report.funnel.0.value', 2));
});

it('makes the trend bars add up to the funnel total for a non-UTC team', function (): void {
    // Two figures, one window: if the trend's lower floor is not converted the same way the
    // window's bounds are, the first bar silently loses the first few hours and the chart
    // disagrees with the funnel about the same question.
    Date::setTestNow('2026-10-30 12:00:00');

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $team->forceFill(['timezone' => 'Asia/Dubai'])->save();

    // Scattered through the window, including right against its opening edge.
    leadCreatedAt($team, '2026-10-23 12:30:00');
    leadCreatedAt($team, '2026-10-24 06:00:00');
    leadCreatedAt($team, '2026-10-27 19:00:00');
    leadCreatedAt($team, '2026-10-30 11:00:00');

    $this->actingAs($user)
        ->get(route('dashboard', ['range' => 'last_7_days']))
        ->assertOk()
        ->assertInertia(function ($page) {
            $report = $page->toArray()['props']['report'];

            expect(collect($report['trend'])->sum('sourced'))
                ->toBe($report['funnel'][0]['value']);

            return $page;
        });
});

it('never cuts a window into more buckets than one query can carry', function (): void {
    // A weekly window opening mid-week borrows days from the week before, so its length is not
    // the number of bars. The ceiling has to allow for that or the oldest bucket is dropped and
    // its leads vanish from the chart while the funnel still counts them.
    Date::setTestNow('2026-10-30 12:00:00');

    foreach ([35, 36, 200, 357, 358, 359, 400, 2_000] as $days) {
        $range = DateRange::for(
            ReportRange::Custom,
            'UTC',
            Date::now('UTC')->subDays($days)->toDateString(),
            Date::now('UTC')->toDateString(),
        );

        $buckets = TrendBuckets::forRange($range);

        expect($buckets->count())
            ->toBeLessThanOrEqual(52, "A {$days}-day window asked for too many buckets.");
    }
});

it('steps a 358-day window up to months before it can overflow', function (): void {
    // A Sunday start is the worst case for weekly bucketing: the first bucket reaches six days
    // back. 358 days is the largest window that still fits in 52 weekly buckets.
    Date::setTestNow('2026-10-30 12:00:00');

    $sundayStart = DateRange::for(ReportRange::Custom, 'UTC', '2025-11-09', '2026-10-30');
    $justOver = DateRange::for(ReportRange::Custom, 'UTC', '2025-11-08', '2026-10-30');

    expect($sundayStart->days())->toBe(356)
        ->and($sundayStart->granularity())->toBe(TrendGranularity::Week)
        ->and(TrendBuckets::forRange($sundayStart)->count())->toBeLessThanOrEqual(52)
        ->and($justOver->days())->toBe(357)
        ->and(TrendBuckets::forRange($justOver)->count())->toBeLessThanOrEqual(52);
});

it('treats a window wholly in the future as no window at all', function (): void {
    // Capping `to` at now collapses such a window to `from > to`: an inverted interval that would
    // match nothing while `days()` still reported a length and the trend tried to bar-chart it.
    Date::setTestNow('2026-10-30 12:00:00');

    $range = DateRange::for(ReportRange::Custom, 'UTC', '2027-01-01', '2027-01-31');

    expect($range->isBounded())->toBeFalse()
        ->and($range->preset)->toBe(ReportRange::AllTime);
});

it('shows an empty window as an empty window, not as an empty account', function (): void {
    // A team with leads that filters to a quiet week must not be told "here's how Alima works".
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    leadCreatedAt($team, now()->subDays(100)->toDateTimeString());

    $this->actingAs($user)
        ->get(route('dashboard', ['range' => 'last_7_days']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.has_data', false)
            ->where('report.has_ever_sourced', true));

    // A genuinely new account gets the explainer.
    $fresh = createUserWithTeam();

    $this->actingAs($fresh)
        ->get(route('dashboard', ['range' => 'last_7_days']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.has_data', false)
            ->where('report.has_ever_sourced', false));
});
