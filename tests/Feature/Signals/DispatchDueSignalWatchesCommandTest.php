<?php

declare(strict_types=1);

use App\Modules\Signals\Enums\SignalWatchCadence;
use App\Modules\Signals\Jobs\RunSignalWatchJob;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues a run only for active watches whose cadence is due', function (): void {
    Queue::fake();

    $neverRun = SignalWatch::factory()->create(['is_active' => true, 'last_run_at' => null]);
    $staleDaily = SignalWatch::factory()->create([
        'is_active' => true,
        'cadence' => SignalWatchCadence::Daily,
        'last_run_at' => now()->subHours(25),
    ]);
    $freshDaily = SignalWatch::factory()->create([
        'is_active' => true,
        'cadence' => SignalWatchCadence::Daily,
        'last_run_at' => now()->subHours(1),
    ]);
    $staleButPaused = SignalWatch::factory()->create([
        'is_active' => false,
        'last_run_at' => now()->subDays(30),
    ]);
    $weeklyNotYet = SignalWatch::factory()->create([
        'is_active' => true,
        'cadence' => SignalWatchCadence::Weekly,
        'last_run_at' => now()->subDays(3), // < 7 days
    ]);

    $this->artisan('signals:dispatch-due')->assertSuccessful();

    $due = [$neverRun->getKey(), $staleDaily->getKey()];

    Queue::assertPushed(RunSignalWatchJob::class, 2);
    foreach ($due as $watchId) {
        Queue::assertPushed(RunSignalWatchJob::class, fn (RunSignalWatchJob $job): bool => $job->watchId === $watchId);
    }
    foreach ([$freshDaily, $staleButPaused, $weeklyNotYet] as $watch) {
        Queue::assertNotPushed(RunSignalWatchJob::class, fn (RunSignalWatchJob $job): bool => $job->watchId === $watch->getKey());
    }
});
