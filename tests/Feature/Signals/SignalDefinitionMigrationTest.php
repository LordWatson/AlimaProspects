<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The S7 data migration, which is the phase's one genuinely risky change: it touches every existing
 * team's live watches, and a half-applied run would leave signals pointing at nothing.
 *
 * It cannot be exercised by running `migrate` inside a test — the suite starts from a fully migrated
 * database. So this file **recreates the v1 shape it was written against**, runs the migration's own
 * `up()` over it, and checks the result. That keeps the assertions on the real file rather than on a
 * copy of its logic.
 *
 * It was also run against the local MySQL by hand, round-trip, which is how two MySQL-only failures
 * were found (a stored generated column refusing a cascading FK, and an index name past 64
 * characters). Neither would ever have shown up here. See `memory/sqlite-tests-mysql-prod.md`.
 */

uses(RefreshDatabase::class);

/**
 * The migration instance, loaded from its own file so the test can only ever pass by the real one
 * working.
 */
/**
 * The three presets the S7 migration creates — **frozen**, like the migration itself.
 *
 * It read `v1PresetKeys()` until S9 filled the library out into
 * vertical packs and that became twenty-four keys. The migration did not change; the question the
 * test was asking did. A frozen migration deserves a frozen expectation.
 *
 * @return list<string>
 */
function v1PresetKeys(): array
{
    return [
        SignalPresetCatalogue::ACQUISITION,
        SignalPresetCatalogue::FUNDING_ROUND,
        SignalPresetCatalogue::RELOCATION,
    ];
}

function eventTypeMigration(): object
{
    return require base_path('database/migrations/2026_11_02_100004_migrate_signal_event_types_to_definitions.php');
}

/**
 * The keys a watch ended up watching for, read without the team scope — see the note at the call
 * site for why that matters here.
 *
 * @return list<string>
 */
function watchedKeys(SignalWatch $watch): array
{
    return $watch->definitions()
        ->withoutGlobalScope('team')
        ->orderBy('signal_definitions.key')
        ->pluck('signal_definitions.key')
        ->all();
}

/**
 * Put the database back into the shape the migration expects to find: `signal_watches.event_types`
 * present, the definitions and the pivot empty, and `signals.signal_definition_id` null.
 */
function rewindToV1Shape(): void
{
    Schema::table('signal_watches', function ($table): void {
        $table->json('event_types')->nullable();
    });

    DB::table('signal_watch_definition')->delete();
    DB::table('signals')->update(['signal_definition_id' => null]);
    SignalDefinition::withoutGlobalScope('team')->withTrashed()->forceDelete();
}

function unwindV1Shape(): void
{
    Schema::table('signal_watches', function ($table): void {
        $table->dropColumn('event_types');
    });
}

it('repoints every watch and signal at the team’s own clone of each preset', function (): void {
    $teamA = createUserWithTeam()->currentTeam()->firstOrFail();
    $teamB = createUserWithTeam()->currentTeam()->firstOrFail();

    // Team A: two watches — one of them **paused**, one watching a single type — plus a signal that
    // has already been **converted**, which is the row whose provenance must survive.
    $watchAll = SignalWatch::withoutGlobalScopes()->create([
        'team_id' => $teamA->getKey(), 'name' => 'All events', 'market' => 'uae',
        'locations' => ['Dubai'], 'regions' => ['ae'], 'cadence' => 'daily', 'is_active' => true,
    ]);
    $watchPaused = SignalWatch::withoutGlobalScopes()->create([
        'team_id' => $teamA->getKey(), 'name' => 'Paused', 'market' => 'uae',
        'locations' => ['Dubai'], 'regions' => ['ae'], 'cadence' => 'weekly', 'is_active' => false,
    ]);
    $watchB = SignalWatch::withoutGlobalScopes()->create([
        'team_id' => $teamB->getKey(), 'name' => 'Team B', 'market' => 'uae',
        'locations' => ['Abu Dhabi'], 'regions' => ['ae'], 'cadence' => 'daily', 'is_active' => true,
    ]);

    $runA = DB::table('signal_runs')->insertGetId([
        'team_id' => $teamA->getKey(), 'signal_watch_id' => $watchAll->getKey(),
        'status' => 'completed', 'queries_count' => 1, 'results_count' => 1,
        'signals_created' => 1, 'cost_micros' => 1000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    rewindToV1Shape();

    DB::table('signal_watches')->where('id', $watchAll->getKey())
        ->update(['event_types' => json_encode(v1PresetKeys())]);
    DB::table('signal_watches')->where('id', $watchPaused->getKey())
        ->update(['event_types' => json_encode([SignalPresetCatalogue::RELOCATION])]);
    DB::table('signal_watches')->where('id', $watchB->getKey())
        ->update(['event_types' => json_encode([SignalPresetCatalogue::FUNDING_ROUND])]);

    $signalIds = [];
    foreach ([
        [SignalPresetCatalogue::FUNDING_ROUND, 'new'],
        [SignalPresetCatalogue::ACQUISITION, 'converted'],
        [SignalPresetCatalogue::RELOCATION, 'dismissed'],
    ] as $index => [$key, $status]) {
        $url = 'https://example.test/a'.$index;
        $signalIds[$key] = DB::table('signals')->insertGetId([
            'team_id' => $teamA->getKey(), 'signal_watch_id' => $watchAll->getKey(),
            'signal_run_id' => $runA, 'event_key' => $key, 'status' => $status,
            'headline' => 'Something happened', 'summary' => 'A summary.',
            'confidence' => 90, 'relevance' => 80, 'source_url' => $url,
            // `dedupe_hash` is NOT NULL since S11, and for an ordinary publisher link it **is**
            // the URL hash - which is what the S11 migration backfilled every existing row with.
            'url_hash' => sha1($url), 'dedupe_hash' => sha1($url),
            'raw' => json_encode(['link' => $url]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    eventTypeMigration()->up();

    // --- the presets exist once, in the platform scope -----------------------------------------
    $presets = SignalDefinition::withoutGlobalScope('team')->whereNull('team_id')->get();

    expect($presets)->toHaveCount(3)
        ->and($presets->pluck('key')->sort()->values()->all())->toBe(v1PresetKeys())
        ->and($presets->pluck('scope_id')->unique()->all())->toBe([SignalDefinition::PLATFORM_SCOPE])
        // A preset is not itself a clone, so it carries no provenance of one.
        ->and($presets->pluck('preset_key')->filter()->all())->toBe([]);

    // --- each team got its own clones, with provenance ------------------------------------------
    foreach ([$teamA, $teamB] as $team) {
        $mine = SignalDefinition::withoutGlobalScope('team')->where('team_id', $team->getKey())->get();

        expect($mine)->toHaveCount(3)
            ->and($mine->pluck('preset_key')->sort()->values()->all())->toBe(v1PresetKeys())
            ->and($mine->pluck('preset_version')->unique()->all())->toBe([1])
            // The generated column agrees with the team, which is what makes `unique(scope_id, key)`
            // mean "one key per scope".
            ->and($mine->pluck('scope_id')->unique()->all())->toBe([(int) $team->getKey()])
            // The templates came across unchanged, so nothing any watch finds has changed.
            ->and($mine->firstWhere('key', SignalPresetCatalogue::FUNDING_ROUND)->query_templates)
            ->toBe([
                '{location} startup raises funding',
                'UAE Series B funding',
                '{location} startup Series A funding round',
            ]);
    }

    // --- watches kept exactly what they watched ------------------------------------------------
    // Read **without the team scope**, because that is the layer the migration ran at: it writes
    // with raw `DB` and no active team, so an assertion that depended on whichever team happened to
    // be active would be testing the harness rather than the migration. (`createUserWithTeam()`
    // leaves the last team it made active, which is exactly how that goes wrong.)
    expect(watchedKeys($watchAll))->toBe(v1PresetKeys())
        // A paused watch is migrated like any other: un-pausing it must put it back the way it was.
        ->and(watchedKeys($watchPaused))->toBe([SignalPresetCatalogue::RELOCATION])
        ->and(watchedKeys($watchB))->toBe([SignalPresetCatalogue::FUNDING_ROUND]);

    // --- no pivot row crosses a tenant --------------------------------------------------------
    $crossTenant = DB::table('signal_watch_definition as p')
        ->join('signal_watches as w', 'w.id', '=', 'p.signal_watch_id')
        ->join('signal_definitions as d', 'd.id', '=', 'p.signal_definition_id')
        ->where(fn ($q) => $q->whereColumn('p.team_id', '<>', 'w.team_id')
            ->orWhereColumn('p.team_id', '<>', 'd.team_id'))
        ->count();

    expect($crossTenant)->toBe(0);

    // --- every signal points at its own team's definition --------------------------------------
    foreach ($signalIds as $key => $id) {
        $signal = DB::table('signals')->where('id', $id)->first();
        $definition = SignalDefinition::withoutGlobalScope('team')->find($signal->signal_definition_id);

        expect($definition)->not->toBeNull()
            ->and($definition->key)->toBe($key)
            ->and((int) $definition->team_id)->toBe((int) $teamA->getKey())
            // The key stays on the row either way — it is what survives the definition going.
            ->and($signal->event_key)->toBe($key);
    }

    // The converted signal is still converted and still attached to its run: a migration must not
    // touch the provenance of a lead somebody is working.
    expect(DB::table('signals')->where('id', $signalIds[SignalPresetCatalogue::ACQUISITION])->value('status'))
        ->toBe('converted');

    unwindV1Shape();
});

it('is idempotent, so a half-applied run can simply be re-run', function (): void {
    $team = createUserWithTeam()->currentTeam()->firstOrFail();

    $watch = SignalWatch::withoutGlobalScopes()->create([
        'team_id' => $team->getKey(), 'name' => 'A watch', 'market' => 'uae',
        'locations' => ['Dubai'], 'regions' => ['ae'], 'cadence' => 'daily', 'is_active' => true,
    ]);

    rewindToV1Shape();

    DB::table('signal_watches')->where('id', $watch->getKey())
        ->update(['event_types' => json_encode([SignalPresetCatalogue::ACQUISITION])]);

    $migration = eventTypeMigration();
    $migration->up();
    $migration->up();
    $migration->up();

    // Three presets, three clones, one pivot row — not nine of each. A data migration that touches
    // every team's live rows has to be re-runnable rather than cleaned up by hand.
    expect(SignalDefinition::withoutGlobalScope('team')->whereNull('team_id')->count())->toBe(3)
        ->and(SignalDefinition::withoutGlobalScope('team')->where('team_id', $team->getKey())->count())->toBe(3)
        ->and(DB::table('signal_watch_definition')->count())->toBe(1);

    unwindV1Shape();
});

it('leaves a team that never used Signals without a library', function (): void {
    $team = createUserWithTeam()->currentTeam()->firstOrFail();

    rewindToV1Shape();

    eventTypeMigration()->up();

    // A team with neither a watch nor a signal gets nothing: seeding a library into every team that
    // has never opened the feature would be rows nobody asked for. They clone from the library when
    // they set their first watch up.
    expect(SignalDefinition::withoutGlobalScope('team')->where('team_id', $team->getKey())->count())->toBe(0)
        // The presets still exist, because that is what they will clone from.
        ->and(SignalDefinition::withoutGlobalScope('team')->whereNull('team_id')->count())->toBe(3);

    unwindV1Shape();
});

/*
 * The S8 UK-preset migration's rollback, which the S8 review found deleting team data.
 *
 * Its `down()` matched `key` and then `team_id IS NULL **or** preset_key = key`, which reads like
 * tidying up after itself and is in fact deleting a team's own tuned clone of the preset — and with
 * it, by FK, the attribution on every signal that clone had classified. A rollback may remove only
 * what the migration inserted.
 */

function ukPresetMigration(): object
{
    return require base_path('database/migrations/2026_11_02_120006_seed_uk_signal_presets.php');
}

/**
 * Platform rows, read the only way they can be — see `PlatformPresetReader` for why that is rare.
 *
 * @return Builder<SignalDefinition>
 */
function platformDefinitions(): Builder
{
    return SignalDefinition::query()->withoutGlobalScope('team')->whereNull('team_id');
}

it('rolls the UK pack back without touching a team clone of it', function (): void {
    $team = Team::factory()->create();

    // The pack is already there — the migration ran when the test database was built — so this is
    // the state a real rollback meets: platform rows, plus one team's clone of one of them.
    $preset = platformDefinitions()->where('key', 'uk_acquisition')->firstOrFail();

    $clone = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => 'uk_acquisition',
        'name' => 'Acquisition (our wording)',
        'preset_key' => 'uk_acquisition',
        'preset_version' => $preset->version,
    ]));

    ukPresetMigration()->down();

    // The UK pack is gone and nothing else is: asserted by what must still be there rather than
    // by a total, because the library grows (S9 took it to thirty) and a total is a number that
    // goes stale without anybody changing the behaviour it was checking.
    expect(platformDefinitions()->where('key', 'like', 'uk_%')->count())->toBe(0)
        ->and(platformDefinitions()->whereIn('key', v1PresetKeys())->count())->toBe(3)
        ->and(platformDefinitions()->where('key', 'like', 'rec_%')->count())->toBeGreaterThan(0);

    // And the team's own copy, with its own wording, is still there.
    $kept = SignalDefinition::query()->withoutGlobalScope('team')->find($clone->getKey());

    expect($kept)->not->toBeNull()
        ->and($kept->name)->toBe('Acquisition (our wording)');
});
