<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\PlatformMetric;
use App\Modules\Admin\Models\PlatformDailyMetric;
use App\Modules\Admin\Services\Platform\PlatformMetricStore;
use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use Tests\Support\AdminFixtures as Fixtures;
use Tests\Support\BillingFixtures as Billing;

uses(RefreshDatabase::class);

/*
 * The platform rollup (Phase 19c).
 *
 * `platform_daily_metrics` is a **record over the ledgers**, not a counter (ADR 0012's rule for
 * `usage_meters`, applied again) — so the properties worth testing are the ones that make that
 * claim true: running it twice changes nothing, a window can be rebuilt from the ledgers, and it
 * runs while those ledgers still exist.
 */

/** A team created on a given day, so a cohort can be built. */
function teamSignedUpOn(string $day, string $name = 'Acme'): Team
{
    [, $team] = Fixtures::tenant($name);

    $team->forceFill(['created_at' => CarbonImmutable::parse($day.' 09:00:00', 'UTC')])->save();

    return $team->refresh();
}

function rollUp(string $day, array $options = []): void
{
    test()->artisan('platform:roll-up', ['--date' => $day] + $options)->assertSuccessful();
}

/**
 * Move a subscription's `created_at` back, so the rollup's reconstruction of a past day can see it.
 *
 * The rollup reads a subscription's **window** off the row (`created_at`, `ends_at`) rather than
 * asking `PlanResolver`, which answers for *now* — see `PlatformRollup`'s class note. A fixture
 * subscription created a moment ago therefore contributes nothing to a day before it existed, which
 * is correct and is why this helper exists rather than the test being wrong about it.
 */
function backdateSubscription(Subscription $subscription, int $days): Subscription
{
    $subscription->forceFill([
        'created_at' => CarbonImmutable::now('UTC')->subDays($days),
    ])->save();

    return $subscription->refresh();
}

it('writes the day it is asked for and nothing else', function (): void {
    $yesterday = CarbonImmutable::now('UTC')->subDay();

    teamSignedUpOn($yesterday->toDateString());

    rollUp($yesterday->toDateString(), ['--skip-funnel' => true]);

    expect(PlatformDailyMetric::query()
        ->where('metric', PlatformMetric::Signups->value)
        ->where('date', $yesterday->toDateString())
        ->sum('value'))->toBe(1);

    // The day before was not asked for, so it has no rows at all — which is a different statement
    // from "nobody signed up", and the store is what keeps them apart.
    expect(PlatformDailyMetric::query()
        ->where('date', $yesterday->subDay()->toDateString())
        ->count())->toBe(0);
});

it('is idempotent — running it twice leaves exactly the same numbers', function (): void {
    $day = CarbonImmutable::now('UTC')->subDay();

    [, $team] = Fixtures::tenant();
    $team->forceFill(['created_at' => $day->setTime(8, 0)])->save();

    app(TeamContext::class)->run($team, function () use ($team, $day): void {
        AiRequest::factory()->count(3)->create([
            'team_id' => $team->getKey(),
            'created_at' => $day->setTime(10, 0),
            'cost_micros' => 1_500,
            'currency' => 'usd',
            'model' => 'claude-test',
        ]);
    });

    rollUp($day->toDateString());
    $first = PlatformDailyMetric::query()->orderBy('metric')->orderBy('dimension')
        ->get(['metric', 'dimension', 'value'])->toArray();

    rollUp($day->toDateString());
    $second = PlatformDailyMetric::query()->orderBy('metric')->orderBy('dimension')
        ->get(['metric', 'dimension', 'value'])->toArray();

    expect($second)->toBe($first)
        ->and(array_sum(array_column(array_filter(
            $second,
            static fn (array $row): bool => $row['metric'] === PlatformMetric::AiCostMicros->value,
        ), 'value')))->toBe(4_500);
});

it('drops a dimension that no longer applies rather than leaving it behind', function (): void {
    // The failure an upsert alone cannot avoid: yesterday's `google_places` row stays on the screen
    // for ever once today's recompute finds no Google Places calls.
    $day = CarbonImmutable::now('UTC')->subDay();

    PlatformDailyMetric::factory()
        ->metric(PlatformMetric::ProviderCalls, 'google_places')
        ->on($day)
        ->worth(99)
        ->create();

    rollUp($day->toDateString(), ['--skip-funnel' => true]);

    expect(PlatformDailyMetric::query()
        ->where('metric', PlatformMetric::ProviderCalls->value)
        ->where('dimension', 'google_places')
        ->where('date', $day->toDateString())
        ->value('value'))->toBe(0);
});

it('backfills a window, oldest day first', function (): void {
    $days = 3;
    $yesterday = CarbonImmutable::now('UTC')->subDay();

    for ($offset = 0; $offset < $days; $offset++) {
        teamSignedUpOn($yesterday->subDays($offset)->toDateString(), 'Team '.$offset);
    }

    test()->artisan('platform:roll-up', ['--days' => (string) $days])->assertSuccessful();

    for ($offset = 0; $offset < $days; $offset++) {
        expect(PlatformDailyMetric::query()
            ->where('metric', PlatformMetric::Signups->value)
            ->where('date', $yesterday->subDays($offset)->toDateString())
            ->sum('value'))->toBe(1);
    }
});

it('leaves point-in-time state alone when reconstructing an older day', function (): void {
    // Dunning, seats, allowance consumption and reported overage are not historised anywhere, so a
    // backfill must not stamp today's state onto last month. It neither writes nor deletes them.
    $old = CarbonImmutable::now('UTC')->subDays(10);

    PlatformDailyMetric::factory()
        ->metric(PlatformMetric::SeatsUsed)
        ->on($old)
        ->worth(42)
        ->create();

    rollUp($old->toDateString(), ['--skip-funnel' => true]);

    expect(PlatformDailyMetric::query()
        ->where('metric', PlatformMetric::SeatsUsed->value)
        ->where('date', $old->toDateString())
        ->value('value'))->toBe(42);

    // Yesterday, by contrast, is the nightly case and is written.
    $yesterday = CarbonImmutable::now('UTC')->subDay();
    rollUp($yesterday->toDateString(), ['--skip-funnel' => true]);

    expect(PlatformDailyMetric::query()
        ->where('metric', PlatformMetric::SeatsUsed->value)
        ->where('date', $yesterday->toDateString())
        ->exists())->toBeTrue();
});

it('runs before every nightly sweep that prunes what it reads', function (): void {
    // The ordering trap, asserted against the real schedule. Compliance's sweep prunes the rows
    // these figures come from, and a period that has been swept cannot be recomputed — so a later
    // phase moving a prune earlier has to fail here rather than silently lose the business's
    // numbers.
    $times = [];

    foreach (app(Schedule::class)->events() as $event) {
        $command = (string) $event->command;

        foreach (['platform:roll-up', 'compliance:purge', 'signals:prune', 'insights:prune'] as $needle) {
            if (str_contains($command, $needle)) {
                // `'40 2 * * *'` → minutes past midnight, which is comparable across commands.
                [$minute, $hour] = explode(' ', $event->expression);
                $times[$needle] = ((int) $hour * 60) + (int) $minute;
            }
        }
    }

    expect($times)->toHaveKey('platform:roll-up');

    foreach (['compliance:purge', 'signals:prune', 'insights:prune'] as $sweep) {
        expect($times)->toHaveKey($sweep);
        expect($times['platform:roll-up'])->toBeLessThan(
            $times[$sweep],
            'platform:roll-up must run before '.$sweep.', which prunes what it reads.',
        );
    }
});

/*
 * The onboarding funnel's cohort rule — the figure most easily got wrong, because its rows are
 * dated by the day a cohort **signed up** rather than by the day anything happened.
 */

it('dates a funnel step by the cohort day, not the day the step happened', function (): void {
    $signupDay = CarbonImmutable::now('UTC')->subDays(5);
    $team = teamSignedUpOn($signupDay->toDateString());

    // The discovery happens *today*, four days after the cohort day.
    app(TeamContext::class)->run($team, function () use ($team): void {
        DiscoveryRun::factory()->create([
            'team_id' => $team->getKey(),
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    });

    test()->artisan('platform:roll-up')->assertSuccessful();

    $store = app(PlatformMetricStore::class);
    $day = DateRange::for(ReportRange::Custom, 'UTC', $signupDay->toDateString(), $signupDay->toDateString());

    expect($store->total(PlatformMetric::FunnelSignedUp, $day))->toBe(1)
        // Dated by the cohort, not by today. This is the assertion the whole `isCohort()` split
        // exists for: treated as event-shaped, every step after the first would read as zero.
        ->and($store->total(PlatformMetric::FunnelDiscovery, $day))->toBe(1);

    $today = DateRange::for(
        ReportRange::Custom,
        'UTC',
        CarbonImmutable::now('UTC')->toDateString(),
        CarbonImmutable::now('UTC')->toDateString(),
    );

    expect($store->total(PlatformMetric::FunnelDiscovery, $today))->toBe(0);
});

it('counts a tenant as activated only once a member has worked a prospect', function (): void {
    $day = CarbonImmutable::now('UTC')->subDay();
    $team = teamSignedUpOn($day->toDateString());
    $lead = Fixtures::lead($team, 'Prospect Ltd');

    // A system activity — discovery — is something Alima did, not something the team did.
    app(TeamContext::class)->run($team, function () use ($team, $lead, $day): void {
        LeadActivity::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'type' => LeadActivityType::Discovered->value,
            'occurred_at' => $day->setTime(9, 0),
        ]);
    });

    rollUp($day->toDateString(), ['--skip-funnel' => true]);

    expect(app(PlatformMetricStore::class)->total(
        PlatformMetric::Activations,
        DateRange::for(ReportRange::Custom, 'UTC', $day->toDateString(), $day->toDateString()),
    ))->toBe(0);

    // A note is a member doing something.
    app(TeamContext::class)->run($team, function () use ($team, $lead, $day): void {
        LeadActivity::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'type' => LeadActivityType::NoteAdded->value,
            'occurred_at' => $day->setTime(11, 0),
        ]);
    });

    rollUp($day->toDateString(), ['--skip-funnel' => true]);

    expect(app(PlatformMetricStore::class)->total(
        PlatformMetric::Activations,
        DateRange::for(ReportRange::Custom, 'UTC', $day->toDateString(), $day->toDateString()),
    ))->toBe(1);
});

/*
 * The UTC trap. `platform_daily_metrics.date` holds a **day**, and the window's bounds are
 * instants — so the store converts to UTC and then to whole days. A window cut in another timezone
 * must still land on the right days, and the exclusive upper instant must not pull in the day
 * after the window.
 */

it('keeps a window on the right days even when it was cut outside UTC', function (): void {
    $store = app(PlatformMetricStore::class);

    foreach (['2026-10-01', '2026-10-15', '2026-10-31', '2026-11-01'] as $index => $day) {
        PlatformDailyMetric::factory()
            ->metric(PlatformMetric::Signups, 'uk')
            ->on($day)
            ->worth($index + 1)
            ->create();
    }

    // October, cut on a **Dubai** clock. `DateRange` builds its bounds in the timezone it is given;
    // handing those straight to a query would compare Dubai wall-clock against UTC columns and
    // shift the window four hours at both ends — which is what the store's `->utc()` prevents.
    $dubai = DateRange::for(ReportRange::Custom, 'Asia/Dubai', '2026-10-01', '2026-10-31');

    // 1 + 2 + 3; the 1 November row (worth 4) is outside the window and must not be counted —
    // the direction a `<` against the exclusive instant `2026-11-01 00:00:00` would get wrong,
    // because a DATE column compares equal to it.
    expect($store->total(PlatformMetric::Signups, $dubai))->toBe(6);

    $utc = DateRange::for(ReportRange::Custom, 'UTC', '2026-10-01', '2026-10-31');

    expect($store->total(PlatformMetric::Signups, $utc))->toBe(6);
});

it('reads a snapshot from the window and never sums it', function (): void {
    // Thirty days of MRR summed is thirty months of revenue. A snapshot metric is read from the
    // window's last populated day.
    $store = app(PlatformMetricStore::class);

    foreach (['2026-10-01', '2026-10-02'] as $day) {
        PlatformDailyMetric::factory()
            ->metric(PlatformMetric::Mrr, PlanKey::Team->value)
            ->on($day)
            ->worth(12_900)
            ->create();

        // The bookkeeping marker the real rollup writes last. Without it a snapshot reads as
        // nothing at all, which is the whole point of the marker — "we have no figure for that
        // day" and "the figure is zero" are different answers, and a hand-built fixture has to say
        // which one it means.
        PlatformDailyMetric::factory()
            ->metric(PlatformMetric::RollupDay)
            ->on($day)
            ->worth(1)
            ->create();
    }

    $window = DateRange::for(ReportRange::Custom, 'UTC', '2026-10-01', '2026-10-02');

    expect($store->total(PlatformMetric::Mrr, $window))->toBe(12_900);

    // A flow metric over the same two days is summed, which is the other half of the rule.
    PlatformDailyMetric::factory()->metric(PlatformMetric::Signups)->on('2026-10-01')->worth(2)->create();
    PlatformDailyMetric::factory()->metric(PlatformMetric::Signups)->on('2026-10-02')->worth(3)->create();

    expect($store->total(PlatformMetric::Signups, $window))->toBe(5);
});

it('records MRR against the plan in force and moves it when the plan changes', function (): void {
    [, $team] = Billing::team();
    $team->forceFill(['created_at' => CarbonImmutable::now('UTC')->subDays(10)])->save();

    // Backdated, because the rollup reconstructs the plan in force on a past day from the
    // subscription's **window** — a row created a moment ago was not revenue last Tuesday, and the
    // rollup is right to say so.
    backdateSubscription(Billing::subscribe($team, PlanKey::Starter), 10);

    $twoDaysAgo = CarbonImmutable::now('UTC')->subDays(2);
    $yesterday = CarbonImmutable::now('UTC')->subDay();

    rollUp($twoDaysAgo->toDateString(), ['--skip-funnel' => true]);

    $starter = (int) config('billing.plans.'.PlanKey::Starter->value.'.amount');

    expect(app(PlatformMetricStore::class)->total(
        PlatformMetric::Mrr,
        DateRange::for(ReportRange::Custom, 'UTC', $twoDaysAgo->toDateString(), $twoDaysAgo->toDateString()),
    ))->toBe($starter);

    backdateSubscription(Billing::moveToPlan($team, PlanKey::Scale), 2);

    rollUp($yesterday->toDateString(), ['--skip-funnel' => true]);

    $scale = (int) config('billing.plans.'.PlanKey::Scale->value.'.amount');
    $window = DateRange::for(ReportRange::Custom, 'UTC', $yesterday->toDateString(), $yesterday->toDateString());

    expect(app(PlatformMetricStore::class)->total(PlatformMetric::Mrr, $window))->toBe($scale)
        // Expansion, not new: the tenant had MRR yesterday and has more today.
        ->and(app(PlatformMetricStore::class)->total(PlatformMetric::MrrExpansion, $window))
        ->toBe($scale - $starter)
        ->and(app(PlatformMetricStore::class)->total(PlatformMetric::MrrNew, $window))->toBe(0);
});

/*
 * The findings from the Phase 19c review, each pinned so it cannot come back. Every one of these
 * was a figure that looked right and was not.
 */

it('reports an emptied snapshot as nothing, not as its last non-zero value', function (): void {
    // The frozen-dashboard bug. Snapshots only write rows for non-zero dimensions, so the night the
    // platform's last paying tenant cancels, MRR writes nothing — and a reader that took "the last
    // populated day" would report the old revenue for as long as the window contained it.
    [, $team] = Billing::team();
    $team->forceFill(['created_at' => CarbonImmutable::now('UTC')->subDays(10)])->save();
    backdateSubscription(Billing::subscribe($team, PlanKey::Starter), 10);

    $twoDaysAgo = CarbonImmutable::now('UTC')->subDays(2);
    $yesterday = CarbonImmutable::now('UTC')->subDay();

    rollUp($twoDaysAgo->toDateString(), ['--skip-funnel' => true]);

    $starter = (int) config('billing.plans.'.PlanKey::Starter->value.'.amount');
    $store = app(PlatformMetricStore::class);

    expect($store->total(
        PlatformMetric::Mrr,
        DateRange::for(ReportRange::Custom, 'UTC', $twoDaysAgo->toDateString(), $twoDaysAgo->toDateString()),
    ))->toBe($starter);

    // They cancel outright — no grace period, so nothing is in force any more.
    Subscription::query()->where('team_id', $team->getKey())->delete();
    Billing::onFreePlan($team);

    rollUp($yesterday->toDateString(), ['--skip-funnel' => true]);

    // The window spans both days. The answer has to be the **later** day's state, which is nothing.
    $window = DateRange::for(
        ReportRange::Custom,
        'UTC',
        $twoDaysAgo->toDateString(),
        $yesterday->toDateString(),
    );

    expect($store->total(PlatformMetric::Mrr, $window))->toBe(0)
        ->and($store->total(PlatformMetric::PayingTeams, $window))->toBe(0)
        // And the churn is recorded against the plan they left, not lost.
        ->and($store->total(PlatformMetric::MrrChurn, $window))->toBe(-$starter);
});

it('writes no movement at all on a first run, rather than booking the platform as new', function (): void {
    [, $team] = Billing::team();
    $team->forceFill(['created_at' => CarbonImmutable::now('UTC')->subDays(10)])->save();
    backdateSubscription(Billing::subscribe($team, PlanKey::Scale), 10);

    $yesterday = CarbonImmutable::now('UTC')->subDay();

    rollUp($yesterday->toDateString(), ['--skip-funnel' => true]);

    $window = DateRange::for(ReportRange::Custom, 'UTC', $yesterday->toDateString(), $yesterday->toDateString());
    $store = app(PlatformMetricStore::class);

    // There is no earlier day to compare against, so there is no movement to report. Measured
    // against nothing, this tenant would have looked like new business it is not.
    expect($store->total(PlatformMetric::MrrNew, $window))->toBe(0)
        ->and($store->total(PlatformMetric::MrrReactivation, $window))->toBe(0)
        // The level is still recorded; only the change is withheld.
        ->and($store->total(PlatformMetric::Mrr, $window))
        ->toBe((int) config('billing.plans.'.PlanKey::Scale->value.'.amount'));
});

it('measures movement across a night the scheduler missed', function (): void {
    [, $team] = Billing::team();
    $team->forceFill(['created_at' => CarbonImmutable::now('UTC')->subDays(10)])->save();
    backdateSubscription(Billing::subscribe($team, PlanKey::Starter), 10);

    $fourDaysAgo = CarbonImmutable::now('UTC')->subDays(4);
    $yesterday = CarbonImmutable::now('UTC')->subDay();

    rollUp($fourDaysAgo->toDateString(), ['--skip-funnel' => true]);

    // Three nights go missing, then the plan changes, then the command runs again.
    backdateSubscription(Billing::moveToPlan($team, PlanKey::Scale), 2);
    rollUp($yesterday->toDateString(), ['--skip-funnel' => true]);

    $starter = (int) config('billing.plans.'.PlanKey::Starter->value.'.amount');
    $scale = (int) config('billing.plans.'.PlanKey::Scale->value.'.amount');
    $window = DateRange::for(ReportRange::Custom, 'UTC', $yesterday->toDateString(), $yesterday->toDateString());
    $store = app(PlatformMetricStore::class);

    // Expansion against the last day we actually recorded — not a whole platform of new MRR,
    // which is what comparing against an absent "yesterday" would have produced.
    expect($store->total(PlatformMetric::MrrExpansion, $window))->toBe($scale - $starter)
        ->and($store->total(PlatformMetric::MrrNew, $window))->toBe(0)
        ->and($store->total(PlatformMetric::MrrReactivation, $window))->toBe(0);
});

it('counts a cancelled subscription as revenue for the days it was still paid for', function (): void {
    // Stripe's status is not historised, so every customer who has since cancelled reads as
    // `canceled` today. Treating that as dead made a backfill report them as never having paid us,
    // with no churn in the series to explain where they went.
    [, $team] = Billing::team();
    $team->forceFill(['created_at' => CarbonImmutable::now('UTC')->subDays(30)])->save();

    $subscription = Billing::subscribe($team, PlanKey::Team, status: 'canceled');
    $subscription->forceFill([
        'created_at' => CarbonImmutable::now('UTC')->subDays(30),
        // Cancelled, but paid up until yesterday.
        'ends_at' => CarbonImmutable::now('UTC')->subDay(),
    ])->save();

    $tenDaysAgo = CarbonImmutable::now('UTC')->subDays(10);
    rollUp($tenDaysAgo->toDateString(), ['--skip-funnel' => true]);

    expect(app(PlatformMetricStore::class)->total(
        PlatformMetric::Mrr,
        DateRange::for(ReportRange::Custom, 'UTC', $tenDaysAgo->toDateString(), $tenDaysAgo->toDateString()),
    ))->toBe((int) config('billing.plans.'.PlanKey::Team->value.'.amount'));
});

it('never counts a subscription whose first payment was never made', function (): void {
    [, $team] = Billing::team();
    $team->forceFill(['created_at' => CarbonImmutable::now('UTC')->subDays(10)])->save();
    backdateSubscription(Billing::subscribe($team, PlanKey::Scale, status: 'incomplete'), 10);

    $yesterday = CarbonImmutable::now('UTC')->subDay();
    rollUp($yesterday->toDateString(), ['--skip-funnel' => true]);

    expect(app(PlatformMetricStore::class)->total(
        PlatformMetric::Mrr,
        DateRange::for(ReportRange::Custom, 'UTC', $yesterday->toDateString(), $yesterday->toDateString()),
    ))->toBe(0);
});
