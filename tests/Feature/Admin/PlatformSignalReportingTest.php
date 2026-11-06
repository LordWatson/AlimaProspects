<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\PlatformMetric;
use App\Modules\Admin\Enums\PlatformReport;
use App\Modules\Admin\Models\PlatformDailyMetric;
use App\Modules\Admin\Services\Platform\PlatformReportBuilder;
use App\Modules\Admin\Services\Platform\PlatformRollup;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\AdminFixtures as Fixtures;

/*
 * Platform signal reporting (S15 task 6).
 *
 * **Not tenant reporting with the scope removed** (ADR 0016). The questions here are ones no tenant
 * asks: *which library presets are teams actually running, and are they finding anything?* A
 * preset cloned by sixty teams that produced nothing last month is the most interesting row on the
 * screen, and nothing else in the product can say so.
 *
 * Three rules this file holds:
 *
 * - **Attribution is to the preset a type was cloned from**, not to the type: a hundred clones of
 *   `funding_round` are one row, which is the whole point.
 * - **A signal whose type has been deleted is `unattributed`, not dropped**, so the platform total
 *   agrees with the tenants' and the difference is findable.
 * - **`signal_types_in_use` is point-in-time only.** There is no record of when a type was switched
 *   on, so writing it for a reconstructed day would be today's shape stamped with last month's
 *   date — the same rule the other snapshots follow.
 */

uses(RefreshDatabase::class);

function signalDay(string $day): DateRange
{
    return DateRange::for(ReportRange::Custom, 'UTC', $day, $day);
}

/** A representative signal belonging to a team's own type. */
function platformSignal(SignalDefinition $definition, array $attributes = []): Signal
{
    $team = $definition->team()->firstOrFail();

    return app(TeamContext::class)->run($team, fn (): Signal => Signal::factory()->create(array_merge([
        'team_id' => $team->getKey(),
        'signal_definition_id' => $definition->getKey(),
        'event_key' => $definition->key,
        'status' => SignalStatus::New->value,
        'source_kind' => SignalSourceKind::News->value,
    ], $attributes)));
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('attributes signals to the library preset their type was cloned from', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-16 03:00:00', 'UTC'));

    [, $one] = Fixtures::tenant('One Ltd');
    [, $two] = Fixtures::tenant('Two Ltd');

    // Two teams running their own copies of the same preset. One row, not two.
    foreach ([$one, $two] as $team) {
        $clone = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
            'team_id' => $team->getKey(),
            'key' => 'library_type',
            'preset_key' => 'library_type',
            'preset_version' => 1,
            'is_active' => true,
        ]));

        platformSignal($clone, ['created_at' => '2026-10-15 09:00:00']);
        platformSignal($clone, [
            'created_at' => '2026-10-15 10:00:00',
            'status' => SignalStatus::Dismissed->value,
        ]);
    }

    app(PlatformRollup::class)->handle(Carbon::parse('2026-10-15', 'UTC')->toImmutable());

    $table = app(PlatformReportBuilder::class)->one(signalDay('2026-10-15'), PlatformReport::SignalLibrary);

    $row = collect($table->rows)->firstWhere(fn ($candidate) => $candidate->cells['preset'] === 'library_type');

    expect($row)->not->toBeNull()
        ->and($row->cells['found'])->toBe(4)
        ->and($row->cells['kept'])->toBe(2)
        ->and($row->cells['converted'])->toBe(0)
        // Both teams' active clones, counted now rather than on the day.
        ->and($row->cells['in_use'])->toBe(2)
        // The pack is derived from the catalogue rather than rolled up, so "which packs should we
        // invest in?" is always asked of today's packaging over history's signals. A key the
        // catalogue does not know — an own type, an orphan, a promoted submission — has none.
        ->and($row->cells['pack'])->toBe('—');
});

it('calls a type a team built themselves own, and one whose type has gone unattributed', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-16 03:00:00', 'UTC'));

    [, $team] = Fixtures::tenant('Builder Ltd');

    $mine = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => 'all_my_own_work',
        'preset_key' => null,
        'preset_version' => null,
    ]));

    $gone = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => 'deleted_later',
        'preset_key' => null,
    ]));

    platformSignal($mine, ['created_at' => '2026-10-15 09:00:00']);
    platformSignal($gone, ['created_at' => '2026-10-15 09:30:00']);

    // `nullOnDelete` (ADR 0018 §4): the signal survives its type, with a null id.
    app(TeamContext::class)->run($team, fn () => $gone->forceDelete());

    app(PlatformRollup::class)->handle(Carbon::parse('2026-10-15', 'UTC')->toImmutable());

    $found = PlatformDailyMetric::query()
        ->where('metric', PlatformMetric::SignalsFound->value)
        ->pluck('value', 'dimension')
        ->all();

    // Losing the orphan would make the platform's total disagree with the tenants' and nobody
    // would be able to find the difference.
    expect($found)->toHaveKey('own')
        ->and((int) $found['own'])->toBe(1)
        ->and($found)->toHaveKey('unattributed')
        ->and((int) $found['unattributed'])->toBe(1);
});

it('reports which source found them, with each source share', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-16 03:00:00', 'UTC'));

    [, $team] = Fixtures::tenant('Sourced Ltd');

    $definition = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => 'sourced_type',
    ]));

    platformSignal($definition, ['created_at' => '2026-10-15 09:00:00']);
    platformSignal($definition, ['created_at' => '2026-10-15 09:10:00']);
    platformSignal($definition, [
        'created_at' => '2026-10-15 09:20:00',
        'source_kind' => SignalSourceKind::WebSearch->value,
    ]);

    app(PlatformRollup::class)->handle(Carbon::parse('2026-10-15', 'UTC')->toImmutable());

    $table = app(PlatformReportBuilder::class)->one(signalDay('2026-10-15'), PlatformReport::SignalSources);

    // Ordered by how much each source found: the figure that says where it is worth looking.
    expect($table->rows[0]->cells['source'])->toBe(SignalSourceKind::News->value)
        ->and($table->rows[0]->cells['found'])->toBe(2)
        ->and($table->rows[1]->cells['found'])->toBe(1);
});

it('leaves the types-in-use snapshot alone when reconstructing an older day', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-16 03:00:00', 'UTC'));

    [, $team] = Fixtures::tenant('Historic Ltd');

    app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => 'library_type',
        'preset_key' => 'library_type',
        'is_active' => true,
    ]));

    // A backfill of a day well in the past. There is no record of when a type was switched on, so
    // today's shape must not be stamped with last month's date.
    app(PlatformRollup::class)->handle(Carbon::parse('2026-09-01', 'UTC')->toImmutable());

    expect(PlatformDailyMetric::query()
        ->where('metric', PlatformMetric::SignalTypesInUse->value)
        ->where('day', '2026-09-01')
        ->count())->toBe(0);
});

it('counts one event per story rather than one per article', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-16 03:00:00', 'UTC'));

    [, $team] = Fixtures::tenant('Clustered Ltd');

    $definition = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => 'library_type',
        'preset_key' => 'library_type',
    ]));

    $primary = platformSignal($definition, ['created_at' => '2026-10-15 09:00:00']);

    $cluster = app(TeamContext::class)->run($team, fn () => SignalCluster::factory()->create([
        'team_id' => $team->getKey(),
        'primary_signal_id' => $primary->getKey(),
    ]));

    app(TeamContext::class)->run($team, fn () => $primary->forceFill([
        'signal_cluster_id' => $cluster->getKey(),
    ])->save());

    platformSignal($definition, [
        'created_at' => '2026-10-15 09:05:00',
        'signal_cluster_id' => $cluster->getKey(),
    ]);

    app(PlatformRollup::class)->handle(Carbon::parse('2026-10-15', 'UTC')->toImmutable());

    $found = PlatformDailyMetric::query()
        ->where('metric', PlatformMetric::SignalsFound->value)
        ->where('dimension', 'library_type')
        ->value('value');

    // The same rule every other signal figure in the product follows (S11).
    expect((int) $found)->toBe(1);
});
