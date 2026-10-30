<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\TriggerSignalWatchAction;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Jobs\RunSignalWatchJob;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

function triggerWatch(Team $team, SignalWatch $watch): SignalRun
{
    return app(TeamContext::class)->run(
        $team,
        fn (): SignalRun => app(TriggerSignalWatchAction::class)->handle($watch),
    );
}
/**
 * Runs for this watch, counted inside the team so the tenancy scope stays in force.
 */
function runCountFor(Team $team, SignalWatch $watch): int
{
    return app(TeamContext::class)->run($team, fn (): int => SignalRun::query()
        ->where('signal_watch_id', $watch->getKey())
        ->count());
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

it('opens a queued run so the screen has something to show', function (): void {
    Queue::fake();

    $team = Team::factory()->create();
    $watch = SignalWatch::factory()->create(['team_id' => $team->getKey(), 'is_active' => true]);

    $run = triggerWatch($team, $watch);

    // The worker opens nothing until it claims the job, so without this row the redirect lands on
    // a card with no state to render — which is how "Run now" came to look like it did nothing.
    expect($run->status)->toBe(SignalRunStatus::Queued)
        ->and((int) $run->signal_watch_id)->toBe((int) $watch->getKey())
        ->and((int) $run->team_id)->toBe((int) $team->getKey())
        // Queued is not started: the worker stamps `started_at` when it picks the job up.
        ->and($run->started_at)->toBeNull();
});

it('reuses a run still waiting for a worker rather than stacking another', function (): void {
    Queue::fake();

    $team = Team::factory()->create();
    $watch = SignalWatch::factory()->create(['team_id' => $team->getKey(), 'is_active' => true]);

    $first = triggerWatch($team, $watch);

    // The throttle normally stops the second press. This is the case behind it: a queue slow
    // enough that the window has passed and the first run still hasn't been picked up.
    RateLimiter::clear('signals:run-now:'.$watch->getKey());

    $second = triggerWatch($team, $watch);

    expect((int) $second->getKey())->toBe((int) $first->getKey())
        ->and(runCountFor($team, $watch))->toBe(1);
});

it('opens no run when the watch is paused', function (): void {
    Queue::fake();

    $team = Team::factory()->create();
    $watch = SignalWatch::factory()->create(['team_id' => $team->getKey(), 'is_active' => false]);

    expect(fn () => triggerWatch($team, $watch))->toThrow(SignalsException::class);

    // A refused press must leave nothing behind: a stray queued row would show a card working on
    // something no worker will ever run.
    expect(runCountFor($team, $watch))->toBe(0);
});

it('opens no second run when the throttle refuses the press', function (): void {
    Queue::fake();

    $team = Team::factory()->create();
    $watch = SignalWatch::factory()->create(['team_id' => $team->getKey(), 'is_active' => true]);

    triggerWatch($team, $watch);

    expect(fn () => triggerWatch($team, $watch))->toThrow(SignalsException::class)
        ->and(runCountFor($team, $watch))->toBe(1);
});
