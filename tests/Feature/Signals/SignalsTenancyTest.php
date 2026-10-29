<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create a watch, a run and a signal all owned by the given team, under its context.
 *
 * @return array{watch: SignalWatch, run: SignalRun, signal: Signal}
 */
function makeSignalGraph(Team $team, array $signalAttributes = []): array
{
    return app(TeamContext::class)->run($team, function () use ($team, $signalAttributes): array {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);
        $run = SignalRun::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);
        $signal = Signal::factory()->create(array_merge([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'signal_run_id' => $run->getKey(),
        ], $signalAttributes));

        return ['watch' => $watch, 'run' => $run, 'signal' => $signal];
    });
}

it('scopes watches, runs and signals to the active team', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    $context = app(TeamContext::class);

    makeSignalGraph($teamA);

    $context->run($teamB, function (): void {
        expect(SignalWatch::query()->count())->toBe(0)
            ->and(SignalRun::query()->count())->toBe(0)
            ->and(Signal::query()->count())->toBe(0);
    });

    expect(SignalWatch::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(SignalRun::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(Signal::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('hides another team’s watch, run and signal from a find under the active team', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    ['watch' => $watchA, 'run' => $runA, 'signal' => $signalA] = makeSignalGraph($teamA);

    app(TeamContext::class)->run($teamB, function () use ($watchA, $runA, $signalA): void {
        expect(SignalWatch::query()->find($watchA->getKey()))->toBeNull()
            ->and(SignalRun::query()->find($runA->getKey()))->toBeNull()
            ->and(Signal::query()->find($signalA->getKey()))->toBeNull();
    });
});

it('auto-assigns team_id from the active team', function (): void {
    $team = Team::factory()->create();

    ['watch' => $watch, 'run' => $run, 'signal' => $signal] = app(TeamContext::class)->run($team, function (): array {
        $watch = SignalWatch::factory()->create(['team_id' => null, 'icp_id' => null]);
        $run = SignalRun::factory()->create(['team_id' => null, 'signal_watch_id' => $watch->getKey()]);
        $signal = Signal::factory()->create([
            'team_id' => null,
            'signal_watch_id' => $watch->getKey(),
            'signal_run_id' => $run->getKey(),
        ]);

        return ['watch' => $watch, 'run' => $run, 'signal' => $signal];
    });

    expect($watch->team_id)->toBe($team->getKey())
        ->and($run->team_id)->toBe($team->getKey())
        ->and($signal->team_id)->toBe($team->getKey());
});

it('allows the same url_hash in two different teams (unique is per team)', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    makeSignalGraph($teamA, ['url_hash' => str_repeat('a', 40)]);
    makeSignalGraph($teamB, ['url_hash' => str_repeat('a', 40)]);

    expect(Signal::query()->withoutGlobalScopes()->where('url_hash', str_repeat('a', 40))->count())->toBe(2);
});

it('rejects a duplicate url_hash within the same team', function (): void {
    $team = Team::factory()->create();

    ['watch' => $watch, 'run' => $run] = makeSignalGraph($team, ['url_hash' => str_repeat('b', 40)]);

    app(TeamContext::class)->run($team, function () use ($team, $watch, $run): void {
        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'signal_run_id' => $run->getKey(),
            'url_hash' => str_repeat('b', 40),
        ]);
    });
})->throws(QueryException::class);

it('nulls lead_id when the converted lead is deleted (one-way FK)', function (): void {
    $team = Team::factory()->create();

    $signal = app(TeamContext::class)->run($team, function () use ($team): Signal {
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);
        ['signal' => $signal] = makeSignalGraph($team);
        $signal->update(['lead_id' => $lead->getKey()]);
        $lead->delete();

        return $signal;
    });

    expect($signal->refresh()->lead_id)->toBeNull();
});
