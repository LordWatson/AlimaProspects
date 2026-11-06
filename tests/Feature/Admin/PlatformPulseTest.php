<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Admin\Enums\PlatformMetric;
use App\Modules\Admin\Services\Platform\PlatformMetricStore;
use App\Modules\Admin\Services\Platform\PlatformPulse;
use Carbon\CarbonImmutable;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The panel's front page, after the 19d overhaul: health first, then thirty days of trend from the
 * same nightly rollup platform reporting reads (ADR 0016), then the live counts.
 */

function enrolledOperator(): User
{
    test()->seed(SuperAdminSeeder::class);

    $operator = User::query()->where('email', SuperAdminSeeder::EMAIL)->sole();

    $operator->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt((string) json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $operator->refresh();
}

/** Write a day of the rollup, the way the nightly job would. */
function rollDay(CarbonImmutable $day, PlatformMetric $metric, int $value): void
{
    app(PlatformMetricStore::class)->replaceDay($day, $metric, ['' => $value]);
}

it('renders the dashboard with health, trended tiles and the chart series', function (): void {
    $operator = enrolledOperator();

    $today = CarbonImmutable::now('UTC');

    rollDay($today, PlatformMetric::RollupDay, 1);
    rollDay($today->subDay(), PlatformMetric::Signups, 3);
    rollDay($today, PlatformMetric::Signups, 5);

    $this->actingAs($operator)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Dashboard')
            // Five trended headlines, in `StatTile`'s own shape — the same component the tenant
            // dashboard uses, so the two cannot drift apart.
            ->has('tiles', 5)
            ->has('tiles.0.spark')
            ->has('tiles.0.delta')
            ->where('tiles.0.key', PlatformMetric::Mrr->value)
            // Three series, switched client-side: a dashboard that reloads to change a line is a
            // dashboard nobody toggles.
            ->has('series', 3)
            ->where('series.0.key', PlatformMetric::Signups->value)
            ->has('series.0.points')
            ->where('health.ok', true)
            ->where('health.rollup_stale', false)
            // The live counts stay — demoted, not dropped.
            ->has('stats.teams'));
});

it('says the figures are stale when last night’s rollup did not run', function (): void {
    $operator = enrolledOperator();

    // No `RollupDay` row at all, which is what a fresh installation and a broken scheduler look
    // like. It is the one state that invalidates every other figure on the page, so it is the one
    // the page leads with.
    $this->actingAs($operator)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('health.rollup_stale', true)
            ->where('health.ok', false)
            ->where('health.rollup_day', null));
});

it('reads the trend from the rollup rather than counting live', function (): void {
    $today = CarbonImmutable::now('UTC');

    rollDay($today->subDays(2), PlatformMetric::Signups, 2);
    rollDay($today, PlatformMetric::Signups, 4);

    $series = collect(app(PlatformPulse::class)->series())
        ->firstWhere('key', PlatformMetric::Signups->value);

    expect($series)->not->toBeNull()
        ->and($series['points'])->toHaveCount(2)
        ->and($series['points'][1]['value'])->toBe(4);

    $tile = collect(app(PlatformPulse::class)->tiles())
        ->firstWhere('key', PlatformMetric::Signups->value);

    // Six over the window, and six more than the thirty days before it.
    expect($tile['value'])->toBe(6)
        ->and($tile['delta'])->toBe(6)
        ->and($tile['spark'])->toBe([2, 4]);
});

it('reports a broken queue and an open breaker as a warning, not a fire', function (): void {
    $today = CarbonImmutable::now('UTC');
    rollDay($today, PlatformMetric::RollupDay, 1);

    // A failed job is somebody's work not happening, which is a warning; a rollup that never ran
    // makes every figure on the page wrong, which is not.
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{"displayName":"App\\\\Jobs\\\\Whatever"}',
        'exception' => 'Something went wrong',
        'failed_at' => now(),
    ]);

    $health = app(PlatformPulse::class)->health();

    expect($health['failed_jobs'])->toBe(1)
        ->and($health['rollup_stale'])->toBeFalse()
        ->and($health['ok'])->toBeFalse();
});
