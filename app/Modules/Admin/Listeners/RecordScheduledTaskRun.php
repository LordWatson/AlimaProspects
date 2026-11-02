<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Services\Platform\QueueHealth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Remembers when each scheduled command last ran (Phase 19c).
 *
 * **Laravel keeps no record of this.** The scheduler knows what is due and nothing at all about
 * what happened: there is no `scheduled_tasks` table, and `schedule:list` prints the next due time,
 * not the last actual one. So "the nightly rollup has not run since Tuesday" — the single most
 * useful sentence on an operations screen — is a fact nobody was storing.
 *
 * Written to the **cache** rather than a table, deliberately. It is diagnostic rather than
 * business data, it is overwritten on every run, and a durable table would need pruning, a
 * migration and a retention answer for something whose whole value is the last line. The cost is
 * that a flushed cache reports "never seen" rather than the truth — so {@see QueueHealth} says
 * *last seen*, never *last run*, and the screen explains the difference.
 *
 * A **skipped** run is recorded too, and separately. `withoutOverlapping()` skips a command whose
 * previous run is still going, and a stuck overnight job that is silently skipped every night
 * afterwards looks exactly like a healthy schedule unless somebody is counting the skips.
 */
final class RecordScheduledTaskRun
{
    public function __construct(private readonly Cache $cache) {}

    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $this->record($event->task, [
            'outcome' => $event->task->exitCode === 0 ? 'ok' : 'failed',
            'exit_code' => $event->task->exitCode,
            'runtime_ms' => (int) round($event->runtime * 1000),
        ]);
    }

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $this->record($event->task, [
            'outcome' => 'failed',
            'exit_code' => $event->task->exitCode,
            // The message only — never the trace. This lands in a shared cache and is rendered on
            // a screen; a stack trace there is a second copy of something Sentry already has.
            'error' => mb_substr($event->exception->getMessage(), 0, 300),
        ]);
    }

    public function handleSkipped(ScheduledTaskSkipped $event): void
    {
        $this->record($event->task, ['outcome' => 'skipped']);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function record(ScheduledEvent $task, array $details): void
    {
        $this->cache->put(
            // Both sides go through {@see QueueHealth::commandName()}. A second normalisation here
            // would be a key the screen cannot find, which reads as "never ran" for a command that
            // ran five minutes ago.
            QueueHealth::scheduleCacheKey(QueueHealth::commandName($task->command, $task->description)),
            $details + ['at' => CarbonImmutable::now('UTC')->toIso8601String()],
            // A fortnight, which is longer than any gap a daily or hourly command should have and
            // short enough that a command deleted from the schedule stops haunting the screen.
            now()->addDays(14),
        );
    }
}
