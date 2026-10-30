<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Jobs\RunSignalWatchJob;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is unique per watch', function (): void {
    $job = new RunSignalWatchJob(teamId: 1, watchId: 42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('42');
});

it('marks the latest non-terminal run Failed on final failure', function (): void {
    $team = Team::factory()->create();

    $run = app(TeamContext::class)->run($team, function () use ($team): SignalRun {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        return SignalRun::factory()->running()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);
    });

    (new RunSignalWatchJob($team->getKey(), (int) $run->signal_watch_id))->failed(new RuntimeException('boom'));

    expect($run->refresh()->status)->toBe(SignalRunStatus::Failed)
        ->and($run->error)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();
});

it('does nothing for a paused watch', function (): void {
    $team = Team::factory()->create();
    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->inactive()->create(['team_id' => $team->getKey()]));

    $job = new RunSignalWatchJob($team->getKey(), (int) $watch->getKey());
    $job->handle(app(RunSignalWatchAction::class), app(TeamContext::class));

    $count = app(TeamContext::class)->run($team, fn (): int => SignalRun::query()->count());
    expect($count)->toBe(0);
});
