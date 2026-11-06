<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\EnsureDefaultPipelineStagesAction;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Reporting\Actions\BuildSignalPerformanceAction;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Which signal types are worth paying for (Signals S12, Phase 13's machinery).
 *
 * Two rules this file pins, both of them the ones Reporting makes every figure obey: the **cohort**
 * is when the signal was *found*, and the bounds reach the query in **UTC**. The third is S11's:
 * a type is credited per **event**, not per article.
 */

uses(RefreshDatabase::class);

function signalPerformance(Team $team, ?DateRange $range = null): array
{
    $range ??= ReportRange::Last30Days->resolve((string) $team->timezone);

    return app(TeamContext::class)->run(
        $team,
        fn (): array => app(BuildSignalPerformanceAction::class)->handle($team, $range)->rows,
    );
}

/** A signal of a named type, optionally converted onto a lead sitting on a given stage. */
function perfSignal(Team $team, SignalDefinition $definition, array $attributes = [], ?PipelineStage $stage = null): Signal
{
    return app(TeamContext::class)->run($team, function () use ($team, $definition, $attributes, $stage): Signal {
        $lead = null;

        if ($stage !== null) {
            $lead = Lead::factory()->create([
                'team_id' => $team->getKey(),
                'pipeline_stage_id' => $stage->getKey(),
            ]);
        }

        return Signal::factory()->forDefinition($definition)->create(array_merge([
            'team_id' => $team->getKey(),
            'lead_id' => $lead?->getKey(),
            'heat' => 60,
            // **An hour ago, not `now()`.** A rolling window's upper bound is `now` and
            // `DateRange::constrain()` compares with `<`, so a row written in the same second as
            // the window was resolved falls outside it — the timestamps are stored to the second,
            // so the two are equal rather than ordered. A fixture that depended on which
            // microsecond won would pass locally and fail in CI about once a minute.
            'created_at' => now()->subHour(),
        ], $attributes));
    });
}

function perfDefinition(Team $team, string $key, string $name): SignalDefinition
{
    return app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => $key,
        'name' => $name,
    ]));
}

it('counts what each type found, kept and converted', function (): void {
    $team = Team::factory()->create();
    $funding = perfDefinition($team, SignalPresetCatalogue::FUNDING_ROUND, 'Funding round');

    perfSignal($team, $funding);
    perfSignal($team, $funding, ['status' => SignalStatus::Dismissed]);
    perfSignal($team, $funding, ['status' => SignalStatus::Converted]);

    $rows = signalPerformance($team);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->cells['type'])->toBe('Funding round')
        ->and($rows[0]->cells['found'])->toBe(3)
        // "Kept" is "not dismissed" — the question is how much of what it found survived contact
        // with a member.
        ->and($rows[0]->cells['kept'])->toBe(2)
        ->and($rows[0]->cells['converted'])->toBe(1);
});

it('credits a type per event, not per article', function (): void {
    $team = Team::factory()->create();
    $funding = perfDefinition($team, SignalPresetCatalogue::FUNDING_ROUND, 'Funding round');

    $cluster = app(TeamContext::class)->run($team, fn (): SignalCluster => SignalCluster::factory()->create([
        'team_id' => $team->getKey(),
        'event_key' => $funding->key,
    ]));

    $primary = perfSignal($team, $funding, ['signal_cluster_id' => $cluster->getKey()]);
    perfSignal($team, $funding, ['signal_cluster_id' => $cluster->getKey()]);
    perfSignal($team, $funding, ['signal_cluster_id' => $cluster->getKey()]);

    $cluster->forceFill(['primary_signal_id' => $primary->getKey()])->save();

    // A type whose stories get picked up widely would otherwise look three times as productive as
    // one whose stories do not — which is a table about press attention, not about the team.
    expect(signalPerformance($team)[0]->cells['found'])->toBe(1);
});

it('counts a lead that moved off the first column as worked', function (): void {
    $team = Team::factory()->create();
    $funding = perfDefinition($team, SignalPresetCatalogue::FUNDING_ROUND, 'Funding round');

    $stages = app(TeamContext::class)->run($team, fn () => app(EnsureDefaultPipelineStagesAction::class)->handle($team));
    $first = $stages->firstWhere('is_default', true);
    $later = $stages->first(fn (PipelineStage $stage): bool => ! $stage->is_default);

    perfSignal($team, $funding, ['status' => SignalStatus::Converted], $first);
    perfSignal($team, $funding, ['status' => SignalStatus::Converted], $later);

    $rows = signalPerformance($team);

    // Defined by the **team's own board** rather than by us: a lead that has moved off the default
    // column is one somebody picked up. `StageOutcome` only knows won from lost, so inventing an
    // "in progress" case here would have been a guess about every stage in between.
    expect($rows[0]->cells['converted'])->toBe(2)
        ->and($rows[0]->cells['progressed'])->toBe(1);
});

it('still names a type the team has since deleted', function (): void {
    $team = Team::factory()->create();
    $funding = perfDefinition($team, SignalPresetCatalogue::FUNDING_ROUND, 'Funding round');

    perfSignal($team, $funding);

    $funding->delete();

    // The record of how a type performed is the most useful thing this table can hold about one a
    // team has removed (ADR 0018 §4) — and what makes S15's retirement suggestion credible.
    $rows = signalPerformance($team);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->cells['found'])->toBe(1);
});

it('scopes by when the signal was found, not when it converted', function (): void {
    $team = Team::factory()->create();
    $funding = perfDefinition($team, SignalPresetCatalogue::FUNDING_ROUND, 'Funding round');

    // Found two months ago, converted yesterday. The cohort rule puts it in the month it was
    // found, under the type that found it — scoping by the conversion date would answer a
    // different and much less useful question and make the columns incomparable with each other.
    perfSignal($team, $funding, [
        'created_at' => now()->subDays(60),
        'status' => SignalStatus::Converted,
        'actioned_at' => now()->subDay(),
    ]);

    expect(signalPerformance($team))->toHaveCount(0)
        ->and(signalPerformance($team, ReportRange::Last90Days->resolve((string) $team->timezone)))->toHaveCount(1);
});

it('bounds the window in UTC for a team that is not', function (): void {
    $team = Team::factory()->create(['timezone' => 'Asia/Dubai']);
    $funding = perfDefinition($team, SignalPresetCatalogue::FUNDING_ROUND, 'Funding round');

    // Dubai is UTC+4, so a signal found at 01:00 local on the first day of the window is
    // 21:00 UTC the day before. A query given local bounds would miss it — which is the shift
    // `DateRange::constrain()` exists to prevent.
    $range = ReportRange::Last7Days->resolve('Asia/Dubai');

    perfSignal($team, $funding, ['created_at' => now()->subDays(3)]);

    expect(signalPerformance($team, $range))->toHaveCount(1);
});

it('never counts another team’s signals', function (): void {
    $mine = Team::factory()->create();
    $theirs = Team::factory()->create();

    perfSignal($mine, perfDefinition($mine, SignalPresetCatalogue::FUNDING_ROUND, 'Mine'));
    perfSignal($theirs, perfDefinition($theirs, SignalPresetCatalogue::FUNDING_ROUND, 'Theirs'));

    $rows = signalPerformance($mine);

    expect($rows)->toHaveCount(1)->and($rows[0]->cells['type'])->toBe('Mine');
});

it('says so plainly when there is nothing to show', function (): void {
    $team = Team::factory()->create();

    $table = app(TeamContext::class)->run(
        $team,
        fn () => app(BuildSignalPerformanceAction::class)->handle(
            $team,
            ReportRange::Last30Days->resolve((string) $team->timezone),
        ),
    );

    expect($table->rows)->toBe([])->and($table->empty_message)->toContain('No trigger events');
});
