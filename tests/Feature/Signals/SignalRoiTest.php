<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Signals\Actions\ApplySignalTuningSuggestionAction;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Enums\SignalTuningKind;
use App\Modules\Signals\Enums\SignalTuningStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalDefinitionSpend;
use App\Modules\Signals\Models\SignalTuningSuggestion;
use App\Modules\Signals\Services\SignalRoiReader;
use App\Modules\Signals\Services\SignalSpendLedger;
use App\Modules\Signals\Services\SignalTuningAdvisor;
use App\Modules\Signals\Support\SignalDefinitionBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Support\BillingFixtures as Billing;

/*
 * What each signal type found, and what it cost (S15 tasks 2 and 3).
 *
 * Three things here are worth more than the rest.
 *
 * **The split between measured and allocated cost.** A search names exactly one type; a
 * classification call serves every type on the watch at once. One is a measurement and the other an
 * apportionment, the table keeps them in separate columns, and the allocation's parts have to sum
 * to the whole — a figure that quietly loses micros is one nobody can reconcile against the run.
 *
 * **Null is not zero.** Cost per converted lead is null when nothing converted, because a zero
 * would read as "free" when the truth is the money bought nothing. The same rule the heat score
 * follows (ADR 0022), one level up.
 *
 * **The retirement suggestion proposes and never applies.** The strongest assertion in the file is
 * that a proposal changed nothing, which is the promise the whole tuning panel rests on.
 */

uses(RefreshDatabase::class);

/** A type of this team's own, with a key the ROI reader will find. */
function roiDefinition(Team $team, string $key, array $attributes = []): SignalDefinition
{
    return app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create(array_merge([
        'team_id' => $team->getKey(),
        'key' => $key,
        'is_active' => true,
    ], $attributes)));
}

/** A representative signal for a type — one event, which is what the funnel counts. */
function roiSignal(Team $team, string $key, array $attributes = []): Signal
{
    return app(TeamContext::class)->run($team, fn (): Signal => Signal::factory()->create(array_merge([
        'team_id' => $team->getKey(),
        'event_key' => $key,
        'status' => SignalStatus::New->value,
    ], $attributes)));
}

// --- The breakdown ------------------------------------------------------------------------------

it('measures a search cost per type and allocates the AI cost by share of results', function (): void {
    $breakdown = new SignalDefinitionBreakdown;

    // Two types on one watch: one brought 60 headlines, the other 40.
    $breakdown->recordQuery('funding_round', 40);
    $breakdown->recordQuery('funding_round', 20);
    $breakdown->recordQuery('expansion', 40);

    $breakdown->recordSearchCost('funding_round', 2_000);
    $breakdown->recordSearchCost('expansion', 1_000);

    // One classification call, offered both types at once.
    $breakdown->allocateAi(10_000);

    $rows = $breakdown->toArray();

    expect($rows['funding_round']['queries'])->toBe(2)
        ->and($rows['funding_round']['results'])->toBe(60)
        // Measured: a query belongs to exactly one type.
        ->and($rows['funding_round']['search_cost_micros'])->toBe(2_000)
        // Allocated: 60 of 100 headlines.
        ->and($rows['funding_round']['ai_cost_micros'])->toBe(6_000)
        ->and($rows['expansion']['ai_cost_micros'])->toBe(4_000);
});

it('gives the integer-division remainder to the largest contributor so the parts sum to the whole', function (): void {
    $breakdown = new SignalDefinitionBreakdown;

    // Three types with 1, 1 and 1 results share 10 micros: 3 + 3 + 3 leaves 1 unallocated.
    $breakdown->recordQuery('a', 2);
    $breakdown->recordQuery('b', 1);
    $breakdown->recordQuery('c', 1);
    $breakdown->allocateAi(10);

    // The whole cost is accounted for, which is what makes the table reconcilable against
    // `signal_runs.cost_micros`.
    expect($breakdown->totalCostMicros())->toBe(10);

    $rows = $breakdown->toArray();

    expect($rows['a']['ai_cost_micros'])->toBe(6);
});

it('allocates nothing when the run classified nothing', function (): void {
    $breakdown = new SignalDefinitionBreakdown;

    // Four searches, no results. There is nothing to apportion an AI cost to, so the run total
    // stays the whole truth on `signal_runs.cost_micros`.
    $breakdown->recordQuery('funding_round', 0);
    $breakdown->recordSearchCost('funding_round', 500);
    $breakdown->allocateAi(9_000);

    expect($breakdown->toArray()['funding_round']['ai_cost_micros'])->toBe(0)
        ->and($breakdown->totalCostMicros())->toBe(500);
});

// --- The ledger ---------------------------------------------------------------------------------

it('adds a second run of the day to the same row rather than writing another', function (): void {
    $team = Team::factory()->create();
    $definition = roiDefinition($team, 'funding_round');
    $ledger = app(SignalSpendLedger::class);

    $first = new SignalDefinitionBreakdown;
    $first->recordQuery('funding_round', 10);
    $first->recordSearchCost('funding_round', 2_000);

    $second = new SignalDefinitionBreakdown;
    $second->recordQuery('funding_round', 5);
    $second->recordSearchCost('funding_round', 2_000);

    app(TeamContext::class)->run($team, function () use ($ledger, $team, $definition, $first, $second): void {
        $ids = ['funding_round' => (int) $definition->getKey()];

        $ledger->record((int) $team->getKey(), $first, $ids);
        $ledger->record((int) $team->getKey(), $second, $ids);
    });

    $rows = SignalDefinitionSpend::query()->withoutGlobalScopes()->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->queries)->toBe(2)
        ->and((int) $rows->first()->results)->toBe(15)
        ->and((int) $rows->first()->search_cost_micros)->toBe(4_000)
        ->and((int) $rows->first()->signal_definition_id)->toBe((int) $definition->getKey());
});

it('skips a type that was offered to the classifier but never searched', function (): void {
    $team = Team::factory()->create();
    $breakdown = new SignalDefinitionBreakdown;

    // `recordQuery` is only called for an answered query; a type the plan never reached has no
    // row at all, and a zero row would make the ROI card's "queries" column read as though it had
    // been tried.
    $breakdown->recordSearchCost('funding_round', 0);

    app(TeamContext::class)->run($team, fn () => app(SignalSpendLedger::class)
        ->record((int) $team->getKey(), $breakdown));

    expect(SignalDefinitionSpend::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('keeps one team out of another team spending', function (): void {
    $mine = Team::factory()->create();
    $theirs = Team::factory()->create();

    SignalDefinitionSpend::factory()->create(['team_id' => $mine->getKey(), 'event_key' => 'funding_round']);
    SignalDefinitionSpend::factory()->create(['team_id' => $theirs->getKey(), 'event_key' => 'funding_round']);

    $roi = app(SignalRoiReader::class)->forTeam($mine);

    expect($roi)->toHaveKey('funding_round')
        // One team's row, not two: the scoped read, not the table.
        ->and($roi['funding_round']->queries)->toBe(8);
});

// --- The figures --------------------------------------------------------------------------------

it('reports the funnel and the cost for one type, hand-counted', function (): void {
    $team = Team::factory()->create();
    $definition = roiDefinition($team, 'funding_round');

    // Found: four events. Kept: three (one dismissed). Converted: two. Progressed: one — the lead
    // that moved off the board's default column.
    $stage = PipelineStage::factory()->create(['team_id' => $team->getKey(), 'is_default' => true]);
    $moved = PipelineStage::factory()->create(['team_id' => $team->getKey(), 'is_default' => false]);

    roiSignal($team, 'funding_round');
    roiSignal($team, 'funding_round', ['status' => SignalStatus::Dismissed->value]);

    foreach ([$stage, $moved] as $at) {
        $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
            'team_id' => $team->getKey(),
            'pipeline_stage_id' => $at->getKey(),
        ]));

        roiSignal($team, 'funding_round', [
            'status' => SignalStatus::Converted->value,
            'lead_id' => $lead->getKey(),
        ]);
    }

    SignalDefinitionSpend::factory()
        ->forDefinition($definition)
        ->costing(6_000, 4_000)
        ->create(['queries' => 10, 'results' => 200]);

    $figures = app(SignalRoiReader::class)->forTeam($team)['funding_round'];

    expect($figures->found)->toBe(4)
        ->and($figures->kept)->toBe(3)
        ->and($figures->converted)->toBe(2)
        ->and($figures->progressed)->toBe(1)
        ->and($figures->keptRate())->toBe(0.75)
        ->and($figures->searchCostMicros)->toBe(6_000)
        ->and($figures->aiCostMicros)->toBe(4_000)
        ->and($figures->totalCostMicros())->toBe(10_000)
        ->and($figures->costPerConvertedMicros())->toBe(5_000);
});

it('answers null rather than zero for the cost of a lead it never produced', function (): void {
    $team = Team::factory()->create();
    $definition = roiDefinition($team, 'funding_round');

    roiSignal($team, 'funding_round');
    SignalDefinitionSpend::factory()->forDefinition($definition)->costing(50_000)->create();

    $figures = app(SignalRoiReader::class)->forTeam($team)['funding_round'];

    // The money is real and the leads are not. A zero here would read as "free".
    expect($figures->totalCostMicros())->toBe(50_000)
        ->and($figures->costPerConvertedMicros())->toBeNull()
        ->and($figures->convertedRate())->toBe(0.0);
});

it('answers null for a rate over nothing', function (): void {
    $team = Team::factory()->create();

    SignalDefinitionSpend::factory()->create(['team_id' => $team->getKey(), 'event_key' => 'quiet_type']);

    $figures = app(SignalRoiReader::class)->forTeam($team)['quiet_type'];

    expect($figures->found)->toBe(0)
        ->and($figures->keptRate())->toBeNull()
        ->and($figures->hasEnoughHistory(30))->toBeFalse();
});

it('keeps the history of a type the team deleted', function (): void {
    $team = Team::factory()->create();
    $definition = roiDefinition($team, 'funding_round');

    SignalDefinitionSpend::factory()->forDefinition($definition)->create();
    roiSignal($team, 'funding_round');

    app(TeamContext::class)->run($team, fn () => $definition->forceDelete());

    // The key is the identity and the id is only the live attribution (ADR 0018 §4), so a type a
    // team removed still answers "what did that cost us?".
    $roi = app(SignalRoiReader::class)->forTeam($team);

    expect($roi)->toHaveKey('funding_round')
        ->and($roi['funding_round']->found)->toBe(1)
        ->and($roi['funding_round']->queries)->toBe(8);
});

it('bounds the window in UTC, so a date-stored day on the edge is not dropped', function (): void {
    $team = Team::factory()->create();
    $definition = roiDefinition($team, 'funding_round');

    Date::setTestNow('2026-03-15 02:00:00');

    // The first day of a 14-day window. Compared as a date rather than as an instant: a `>=`
    // against a timestamp drops this row on every driver that stores a date as midnight.
    SignalDefinitionSpend::factory()
        ->forDefinition($definition)
        ->on(Date::now()->utc()->subDays(14)->toDateString())
        ->create();

    $roi = app(SignalRoiReader::class)->forTeam($team, 14);

    expect($roi)->toHaveKey('funding_round')
        ->and($roi['funding_round']->days)->toBe(14);

    Date::setTestNow();
});

it('leaves spending from before the window out', function (): void {
    $team = Team::factory()->create();
    $definition = roiDefinition($team, 'funding_round');

    SignalDefinitionSpend::factory()
        ->forDefinition($definition)
        ->on(Date::now()->utc()->subDays(40)->toDateString())
        ->create();

    expect(app(SignalRoiReader::class)->forTeam($team, 7))->toBe([]);
});

// --- The retirement proposal (task 3) ------------------------------------------------------------

it('proposes pausing a type that found plenty, kept almost none and converted nothing', function (): void {
    $team = Team::factory()->create();
    $definition = roiDefinition($team, 'funding_round', ['name' => 'Funding rounds']);

    // 40 events, 2 kept — a 5% keep rate against a 10% floor — and nothing converted.
    for ($i = 0; $i < 38; $i++) {
        roiSignal($team, 'funding_round', ['status' => SignalStatus::Dismissed->value]);
    }

    roiSignal($team, 'funding_round');
    roiSignal($team, 'funding_round');

    SignalDefinitionSpend::factory()->forDefinition($definition)->costing(80_000, 20_000)->create();

    app(SignalTuningAdvisor::class)->advise($team);

    $suggestion = SignalTuningSuggestion::query()
        ->withoutGlobalScopes()
        ->where('kind', SignalTuningKind::RetireDefinition->value)
        ->firstOrFail();

    expect((int) $suggestion->signal_definition_id)->toBe((int) $definition->getKey())
        // The numbers that make the argument. A proposal a member cannot check is one they can
        // only obey or ignore (ADR 0022).
        ->and($suggestion->evidence['found'])->toBe(40)
        ->and($suggestion->evidence['kept'])->toBe(2)
        ->and($suggestion->evidence['rate'])->toBe(0.05)
        ->and($suggestion->evidence['cost_micros'])->toBe(100_000)
        ->and($suggestion->evidence['definition_name'])->toBe('Funding rounds');

    // And the promise the panel rests on: proposing changed nothing.
    expect($definition->fresh()->is_active)->toBeTrue();
});

it('does not propose pausing a type with too little history to judge', function (): void {
    $team = Team::factory()->create();
    roiDefinition($team, 'funding_round');

    // Four signals, none kept. A type with four signals is not evidence of anything.
    for ($i = 0; $i < 4; $i++) {
        roiSignal($team, 'funding_round', ['status' => SignalStatus::Dismissed->value]);
    }

    app(SignalTuningAdvisor::class)->advise($team);

    expect(SignalTuningSuggestion::query()
        ->withoutGlobalScopes()
        ->where('kind', SignalTuningKind::RetireDefinition->value)
        ->count())->toBe(0);
});

it('does not propose pausing a type that has converted something, however much it is dismissed', function (): void {
    $team = Team::factory()->create();
    $definition = roiDefinition($team, 'funding_round');

    for ($i = 0; $i < 39; $i++) {
        roiSignal($team, 'funding_round', ['status' => SignalStatus::Dismissed->value]);
    }

    // One lead in forty is a terrible keep rate and a perfectly good reason to keep running:
    // the type is doing the job it was added for.
    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create(['team_id' => $team->getKey()]));
    roiSignal($team, 'funding_round', [
        'status' => SignalStatus::Converted->value,
        'lead_id' => $lead->getKey(),
    ]);

    SignalDefinitionSpend::factory()->forDefinition($definition)->create();

    app(SignalTuningAdvisor::class)->advise($team);

    expect(SignalTuningSuggestion::query()
        ->withoutGlobalScopes()
        ->where('kind', SignalTuningKind::RetireDefinition->value)
        ->count())->toBe(0);
});

it('does not propose pausing a type that is already paused', function (): void {
    $team = Team::factory()->create();
    roiDefinition($team, 'funding_round', ['is_active' => false]);

    for ($i = 0; $i < 40; $i++) {
        roiSignal($team, 'funding_round', ['status' => SignalStatus::Dismissed->value]);
    }

    app(SignalTuningAdvisor::class)->advise($team);

    expect(SignalTuningSuggestion::query()
        ->withoutGlobalScopes()
        ->where('kind', SignalTuningKind::RetireDefinition->value)
        ->count())->toBe(0);
});

it('pauses the type only when a member accepts, and never raises it again', function (): void {
    [$member, $team] = Billing::team();
    $definition = roiDefinition($team, 'funding_round');

    for ($i = 0; $i < 40; $i++) {
        roiSignal($team, 'funding_round', ['status' => SignalStatus::Dismissed->value]);
    }

    app(SignalTuningAdvisor::class)->advise($team);

    $suggestion = SignalTuningSuggestion::query()
        ->withoutGlobalScopes()
        ->where('kind', SignalTuningKind::RetireDefinition->value)
        ->firstOrFail();

    app(TeamContext::class)->run($team, fn () => app(ApplySignalTuningSuggestionAction::class)->accept($suggestion, $member));

    expect($definition->fresh()->is_active)->toBeFalse()
        ->and($suggestion->fresh()->status)->toBe(SignalTuningStatus::Accepted);

    // Accepting is an ordinary edit and does **not** bump the clone's version: pausing is not a
    // change to what the type looks for.
    expect((int) $definition->fresh()->version)->toBe((int) $definition->version);

    // Asked once. A second run finds the same evidence and says nothing.
    app(SignalTuningAdvisor::class)->advise($team);

    expect(SignalTuningSuggestion::query()
        ->withoutGlobalScopes()
        ->where('kind', SignalTuningKind::RetireDefinition->value)
        ->count())->toBe(1);
});

it('keeps quiet when retirement suggestions are switched off', function (): void {
    config()->set('signals.roi.retire.enabled', false);

    $team = Team::factory()->create();
    roiDefinition($team, 'funding_round');

    for ($i = 0; $i < 40; $i++) {
        roiSignal($team, 'funding_round', ['status' => SignalStatus::Dismissed->value]);
    }

    app(SignalTuningAdvisor::class)->advise($team);

    expect(SignalTuningSuggestion::query()
        ->withoutGlobalScopes()
        ->where('kind', SignalTuningKind::RetireDefinition->value)
        ->count())->toBe(0);
});
