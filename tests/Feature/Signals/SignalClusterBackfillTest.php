<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalItemNormaliser;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * `signals:cluster-backfill` (S11).
 *
 * Without it, clustering applies only to signals found from now on — a team opens the feed to
 * yesterday's eight cards about one acquisition and concludes the feature does not work. So this is
 * about the three properties that make a backfill safe to run on a live install: it is idempotent,
 * it leaves a converted signal alone, and `--dry-run` writes nothing.
 */

uses(RefreshDatabase::class);

/**
 * `$count` signals about one acquisition, as they existed before this phase: no cluster, no content
 * hash.
 *
 * @return list<Signal>
 */
function unclusteredCoverage(Team $team, int $count = 4, string $company = 'Zephyr Logistics LLC', ?SignalStatus $status = null, string $slug = 'zephyr'): array
{
    return app(TeamContext::class)->run($team, function () use ($team, $count, $company, $status, $slug): array {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);
        $run = SignalRun::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);

        $signals = [];

        foreach (range(1, $count) as $index) {
            $url = 'https://outlet'.$index.'.test/'.$slug;

            $signals[] = Signal::factory()->create([
                'team_id' => $team->getKey(),
                'signal_watch_id' => $watch->getKey(),
                'signal_run_id' => $run->getKey(),
                'event_key' => SignalPresetCatalogue::ACQUISITION,
                'status' => $status ?? SignalStatus::New,
                'headline' => 'Regional Holdings acquires Dubai logistics firm Zephyr',
                'subject_company_name' => $company,
                'source_name' => 'Outlet '.$index,
                'source_url' => $url,
                'url_hash' => sha1($url),
                'dedupe_hash' => sha1($url),
                'content_hash' => null,
                'published_at' => now()->subDays(3),
            ]);
        }

        return $signals;
    });
}

it('groups an existing team’s coverage into one cluster', function (): void {
    $team = Team::factory()->create();
    unclusteredCoverage($team, 6);

    $this->artisan('signals:cluster-backfill')->assertSuccessful();

    $cluster = SignalCluster::query()->withoutGlobalScopes()->firstOrFail();

    expect(SignalCluster::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and($cluster->sources_count)->toBe(6)
        ->and($cluster->subject_fingerprint)->toBe('zephyr-logistics')
        ->and($cluster->primary_signal_id)->not->toBeNull();
});

it('fills the content hash the migration deliberately left null', function (): void {
    $team = Team::factory()->create();
    unclusteredCoverage($team, 2);

    $this->artisan('signals:cluster-backfill')->assertSuccessful();

    $signal = Signal::query()->withoutGlobalScopes()->firstOrFail();
    $expected = app(SignalItemNormaliser::class)->contentHash($signal->headline, $signal->source_name);

    expect($signal->content_hash)->toBe($expected);
});

it('is idempotent', function (): void {
    $team = Team::factory()->create();
    unclusteredCoverage($team, 4);

    $this->artisan('signals:cluster-backfill')->assertSuccessful();
    $this->artisan('signals:cluster-backfill')->assertSuccessful();

    expect(SignalCluster::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(SignalCluster::query()->withoutGlobalScopes()->firstOrFail()->sources_count)->toBe(4);
});

it('never merges a converted signal away', function (): void {
    $team = Team::factory()->create();
    unclusteredCoverage($team, 3);
    $converted = unclusteredCoverage($team, 1, status: SignalStatus::Converted, slug: 'zephyr-converted')[0];

    $this->artisan('signals:cluster-backfill')->assertSuccessful();

    // Its `lead_id` is the only copy of where that lead came from, so it stays its own card rather
    // than disappearing behind a primary that never made one.
    expect($converted->refresh()->signal_cluster_id)->toBeNull()
        ->and(SignalCluster::query()->withoutGlobalScopes()->firstOrFail()->sources_count)->toBe(3);
});

it('leaves a signal with no company subject alone', function (): void {
    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, function () use ($team): void {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);
        $run = SignalRun::factory()->create(['team_id' => $team->getKey(), 'signal_watch_id' => $watch->getKey()]);

        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'signal_run_id' => $run->getKey(),
            'subject_company_name' => null,
            'published_at' => now()->subDay(),
        ]);
    });

    $this->artisan('signals:cluster-backfill')->assertSuccessful();

    expect(SignalCluster::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('writes nothing on a dry run', function (): void {
    $team = Team::factory()->create();
    unclusteredCoverage($team, 4);

    $this->artisan('signals:cluster-backfill', ['--dry-run' => true])
        ->expectsOutputToContain('Would group 4 signal(s)')
        ->assertSuccessful();

    expect(SignalCluster::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Signal::query()->withoutGlobalScopes()->whereNotNull('content_hash')->count())->toBe(0);
});

it('does not reach past the window', function (): void {
    $team = Team::factory()->create();
    unclusteredCoverage($team, 3);

    Signal::query()->withoutGlobalScopes()->update(['published_at' => now()->subDays(200)]);

    $this->artisan('signals:cluster-backfill')->assertSuccessful();

    // An archive nobody will look at costs the same to cluster and buys nothing — and a signal that
    // old is past its retention window anyway.
    expect(SignalCluster::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('keeps two teams’ coverage of the same event in two clusters', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    unclusteredCoverage($teamA, 2);
    unclusteredCoverage($teamB, 2);

    $this->artisan('signals:cluster-backfill')->assertSuccessful();

    $clusters = SignalCluster::query()->withoutGlobalScopes()->get();

    expect($clusters)->toHaveCount(2)
        ->and($clusters->pluck('team_id')->unique())->toHaveCount(2)
        ->and($clusters->pluck('sources_count')->all())->toBe([2, 2]);
});

it('sweeps a cluster once its last signal has gone', function (): void {
    $team = Team::factory()->create();
    unclusteredCoverage($team, 2);

    $this->artisan('signals:cluster-backfill')->assertSuccessful();

    Signal::query()->withoutGlobalScopes()->delete();

    // Past the hour's grace. The sweep deliberately leaves a freshly touched cluster alone: a run
    // can find an empty cluster and then spend a moment on its insert, and MySQL turns the
    // resulting FK violation inside an `INSERT IGNORE` into a warning - so the signals would be
    // dropped silently, which is the worst way for this to fail.
    $this->travel(2)->hours();

    // A cluster has no window of its own; emptying is its only expiry, and that is what stops a
    // subject fingerprint outliving every row it was derived from.
    $this->artisan('signals:prune')
        ->expectsOutputToContain('1 empty cluster(s)')
        ->assertSuccessful();

    expect(SignalCluster::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('counts the events it would make on a dry run', function (): void {
    $team = Team::factory()->create();
    unclusteredCoverage($team, 3);
    unclusteredCoverage($team, 2, company: 'Delta Freight Ltd', slug: 'delta');

    // A dry run writes nothing, so it has no cluster ids to count — but "0 clusters" beside
    // "would group 5 signals" read like a bug. It applies the same own-week-then-previous-week
    // join in memory instead.
    $this->artisan('signals:cluster-backfill', ['--dry-run' => true])
        ->expectsOutputToContain('Would group 5 signal(s) into 2 cluster(s)')
        ->assertSuccessful();

    expect(SignalCluster::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('agrees with itself: the dry run’s count is what the real run produces', function (): void {
    $team = Team::factory()->create();
    unclusteredCoverage($team, 3);
    unclusteredCoverage($team, 2, company: 'Delta Freight Ltd', slug: 'delta');

    $this->artisan('signals:cluster-backfill', ['--dry-run' => true])
        ->expectsOutputToContain('into 2 cluster(s)')
        ->assertSuccessful();

    $this->artisan('signals:cluster-backfill')
        ->expectsOutputToContain('into 2 cluster(s)')
        ->assertSuccessful();

    expect(SignalCluster::query()->withoutGlobalScopes()->count())->toBe(2);
});
