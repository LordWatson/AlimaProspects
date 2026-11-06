<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Enums\WatchlistEntityType;
use App\Modules\Signals\Enums\WatchlistSourceKind;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalDefinitionSpend;
use App\Modules\Signals\Models\SignalInboundEvent;
use App\Modules\Signals\Models\SignalPreview;
use App\Modules\Signals\Models\SignalSourceStat;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use App\Modules\Signals\Services\SignalsPersonalDataExporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The queries S16 added that **SQLite cannot vouch for** (task 6).
 *
 * `memory/sqlite-tests-mysql-prod.md` is the standing rule here: a green suite says nothing about
 * whether a query runs on MySQL, and this module has already been bitten by exactly that — the
 * `LIKE ... ESCAPE` character in {@see SignalsPersonalDataExporter} could not be a backslash,
 * because in MySQL the literal `'\'` is an unterminated string while SQLite is perfectly happy with
 * it. That bug passed every test and would have failed every production query.
 *
 * S16 added six more raw `LIKE`s, a `whereDate` over a `date` column, and a nested `orWhere` group
 * with a `whereNull` inside it. Each one is the same class of risk, so each one is exercised here
 * against the real engine.
 *
 * ## Running it
 *
 * **Skipped unless `SIGNALS_MYSQL_TEST_DATABASE` names a throwaway schema**, and gated that way on
 * purpose: this calls `migrate:fresh`, so it must be impossible to point at anything by accident.
 *
 * ```
 * mysql -e "create database alima_mysql_test"
 * SIGNALS_MYSQL_TEST_DATABASE=alima_mysql_test php artisan test tests/Feature/Signals/SignalsMysqlDialectTest.php
 * ```
 *
 * It reads `DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` from the environment like any other
 * connection, so a developer who can already run the app locally needs nothing else. CI runs on
 * SQLite and skips it; the manual run is what `docs/modules/signals.md` records.
 */
beforeEach(function (): void {
    $database = env('SIGNALS_MYSQL_TEST_DATABASE');

    if (! is_string($database) || $database === '') {
        $this->markTestSkipped(
            'Set SIGNALS_MYSQL_TEST_DATABASE to a throwaway MySQL schema to run the dialect checks.'
        );
    }

    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.database', $database);

    DB::purge('mysql');

    try {
        DB::connection('mysql')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('No MySQL connection: '.$e->getMessage());
    }

    Artisan::call('migrate:fresh', ['--database' => 'mysql', '--force' => true]);
});

afterEach(function (): void {
    // The schema is left empty rather than dropped: the next run's `migrate:fresh` handles it, and
    // dropping a database a developer named by hand is not this test's business.
    if (config('database.default') === 'mysql') {
        Artisan::call('db:wipe', ['--database' => 'mysql', '--force' => true]);
        DB::purge('mysql');
    }
});

function mysqlTeam(): Team
{
    return Team::factory()->create();
}

function mysqlExporter(): SignalsPersonalDataExporter
{
    return app(SignalsPersonalDataExporter::class);
}

function mysqlPersonSignal(Team $team, string $name, array $attributes = []): Signal
{
    return app(TeamContext::class)->run($team, function () use ($team, $name, $attributes): Signal {
        $watch = SignalWatch::query()->where('team_id', $team->getKey())->first()
            ?? SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'subject_person_name' => $name,
            ...$attributes,
        ]);
    });
}

it('runs the escaped LIKE on MySQL, and searches for a wildcard rather than with one', function (): void {
    $team = mysqlTeam();

    // The query that has already been wrong once. `!` is the escape character because `'\'` is an
    // unterminated string literal in MySQL — so this assertion is the whole point of the file.
    mysqlPersonSignal($team, 'Jane_Founder');
    mysqlPersonSignal($team, 'JaneXFounder');

    $rows = mysqlExporter()->export($team, 'Jane_Founder');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['subject_person_name'])->toBe('Jane_Founder');
});

it('matches a watchlist person and their JSON aliases on MySQL', function (): void {
    $team = mysqlTeam();

    app(TeamContext::class)->run($team, function () use ($team): void {
        $list = SignalWatchlist::factory()->create([
            'team_id' => $team->getKey(),
            'name' => 'People I watch',
        ]);

        SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'entity_type' => WatchlistEntityType::Person,
            'name' => 'J. Founder',
            // MySQL stores `json` natively rather than as text, which is exactly the difference a
            // `LIKE` over the column depends on and SQLite cannot demonstrate.
            'aliases' => ['Jane Founder'],
        ]);
    });

    $rows = mysqlExporter()->export($team, 'Jane Founder');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['record'])->toBe('watchlist_entry');
});

it('matches a name inside a pushed JSON payload on MySQL', function (): void {
    $team = mysqlTeam();

    $definition = signalDefinition($team);

    app(TeamContext::class)->run($team, fn (): SignalInboundEvent => SignalInboundEvent::factory()->create([
        'team_id' => $team->getKey(),
        'signal_definition_id' => $definition->getKey(),
        'title' => 'A leadership change at Acme',
        'summary' => 'Our CRM recorded a new CFO.',
        'payload' => ['event_type' => 'custom_event', 'person' => ['full_name' => 'Jane Founder']],
    ]));

    $rows = mysqlExporter()->export($team, 'Jane Founder');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['record'])->toBe('inbound_event');
});

it('erases across all three surfaces and repairs the cluster on MySQL', function (): void {
    $team = mysqlTeam();

    $cluster = app(TeamContext::class)->run($team, function () use ($team): SignalCluster {
        $cluster = SignalCluster::factory()->create(['team_id' => $team->getKey()]);

        $named = mysqlPersonSignal($team, 'Jane Founder', ['signal_cluster_id' => $cluster->getKey()]);
        mysqlPersonSignal($team, 'Someone Else', ['signal_cluster_id' => $cluster->getKey()]);

        $cluster->forceFill(['primary_signal_id' => $named->getKey(), 'sources_count' => 2])->save();

        return $cluster;
    });

    app(TeamContext::class)->run($team, function () use ($team): void {
        $list = SignalWatchlist::factory()->create(['team_id' => $team->getKey(), 'name' => 'Watched']);

        SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'entity_type' => WatchlistEntityType::Person,
            'name' => 'Jane Founder',
        ]);
    });

    $result = mysqlExporter()->erase($team, 'Jane Founder');

    expect($result['erased'])->toBe(1)
        ->and($result['watchlist_entries_erased'])->toBe(1)
        ->and($cluster->refresh()->sources_count)->toBe(1)
        ->and($cluster->primary_signal_id)->not->toBeNull();
});

it('runs every S16 retention sweep on MySQL', function (): void {
    $team = mysqlTeam();

    // The two queries in the sweep that SQLite cannot vouch for: `whereDate` over a real `date`
    // column, and a nested `orWhere` group with a `whereNull` inside it.
    app(TeamContext::class)->run($team, function () use ($team): void {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'status' => SignalStatus::Dismissed,
        ])->forceFill(['created_at' => now()->subDays(500)])->saveQuietly();

        SignalPreview::factory()->create(['team_id' => $team->getKey()])
            ->forceFill(['created_at' => now()->subDays(500)])->saveQuietly();

        SignalDefinitionSpend::factory()->create([
            'team_id' => $team->getKey(),
            'event_key' => 'old_key',
            'day' => now()->subDays(900)->toDateString(),
        ]);

        // One stat with a `last_seen_at` and one with none, so both arms of the nested group run.
        SignalSourceStat::factory()->create([
            'team_id' => $team->getKey(),
            'source_key' => 'defunct-weekly',
            'event_key' => 'funding_round',
            'last_seen_at' => now()->subDays(900),
        ]);

        SignalSourceStat::factory()->create([
            'team_id' => $team->getKey(),
            'source_key' => 'never-seen',
            'event_key' => 'funding_round',
            'last_seen_at' => null,
        ])->forceFill(['created_at' => now()->subDays(900)])->saveQuietly();

        $list = SignalWatchlist::factory()->create([
            'team_id' => $team->getKey(),
            'name' => 'Mirrored pipeline',
            'source_kind' => WatchlistSourceKind::Pipeline,
            'is_active' => false,
        ]);

        SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'name' => 'Orphaned Holdings Ltd',
            'lead_id' => null,
        ]);
    });

    Artisan::call('signals:prune');

    expect(Signal::withoutGlobalScopes()->count())->toBe(0)
        ->and(SignalPreview::withoutGlobalScopes()->count())->toBe(0)
        ->and(SignalDefinitionSpend::withoutGlobalScopes()->count())->toBe(0)
        ->and(SignalSourceStat::withoutGlobalScopes()->count())->toBe(0)
        ->and(SignalWatchlistEntry::withoutGlobalScopes()->count())->toBe(0);
});
