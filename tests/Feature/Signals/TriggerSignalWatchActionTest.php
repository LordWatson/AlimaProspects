<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\TriggerSignalWatchAction;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Jobs\RunSignalWatchJob;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function triggerWatch(Team $team, SignalWatch $watch): void
{
    app(TeamContext::class)->run($team, fn () => app(TriggerSignalWatchAction::class)->handle($watch));
}

it('dispatches a run for an active watch', function (): void {
    Queue::fake();

    $team = Team::factory()->create();
    $watch = SignalWatch::factory()->create(['team_id' => $team->getKey(), 'is_active' => true]);

    triggerWatch($team, $watch);

    Queue::assertPushed(RunSignalWatchJob::class, fn (RunSignalWatchJob $job): bool => $job->watchId === $watch->getKey());
});

it('throttles a second run-now within the window', function (): void {
    Queue::fake();

    $team = Team::factory()->create();
    $watch = SignalWatch::factory()->create(['team_id' => $team->getKey(), 'is_active' => true]);

    triggerWatch($team, $watch);

    expect(fn () => triggerWatch($team, $watch))->toThrow(SignalsException::class);

    Queue::assertPushed(RunSignalWatchJob::class, 1); // only the first went through
});

it('refuses to run an inactive watch', function (): void {
    Queue::fake();

    $team = Team::factory()->create();
    $watch = SignalWatch::factory()->create(['team_id' => $team->getKey(), 'is_active' => false]);

    expect(fn () => triggerWatch($team, $watch))->toThrow(SignalsException::class);

    Queue::assertNothingPushed();
});
