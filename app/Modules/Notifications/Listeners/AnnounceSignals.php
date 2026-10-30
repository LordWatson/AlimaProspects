<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Signals\Events\SignalsDetected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the team a watch brought back new signals (Phase 12).
 *
 * No aggregation needed, unlike the A-grade announcement: a signal run already arrives as **one
 * event carrying a count** for the whole batch, because Signals had the same problem and solved it
 * at the source (`SignalsDetected`). The event's own broadcast raises the in-feed banner for a tab
 * that happens to be open; this is what reaches a member who isn't looking.
 */
final class AnnounceSignals implements ShouldQueue
{
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public int $timeout = 60;

    public bool $afterCommit = true;

    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(SignalsDetected $event): void
    {
        if ($event->count < 1) {
            return;
        }

        $team = Team::query()->find($event->teamId);

        if (! $team instanceof Team) {
            return;
        }

        $this->dispatcher->toTeam($team, NotificationType::SignalsDetected, [
            'count' => $event->count,
            'run_id' => $event->runId,
        ]);
    }

    public function failed(SignalsDetected $event, Throwable $exception): void
    {
        Log::channel('notifications')->error('Could not announce new signals.', [
            'team_id' => $event->teamId,
            'run_id' => $event->runId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
