<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Events\DiscoveryRunCompleted;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the team their lead search has finished (Phase 12).
 *
 * To the **whole team**, not just whoever started it: a search is the team's work, a colleague may
 * pick the leads up, and the member who pressed the button is the one most likely to already know.
 *
 * A run that found nothing still notifies. That is deliberate — it means the ICP or the search terms
 * need a look, which is more useful to know than to discover a week later.
 */
final class AnnounceDiscoveryRun implements ShouldQueue
{
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public int $timeout = 60;

    public bool $afterCommit = true;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function handle(DiscoveryRunCompleted $event): void
    {
        $team = Team::query()->find($event->teamId);

        if (! $team instanceof Team) {
            return;
        }

        $run = $this->teamContext->run($team, fn (): ?DiscoveryRun => DiscoveryRun::query()->find($event->runId));

        if (! $run instanceof DiscoveryRun) {
            return;
        }

        $this->dispatcher->toTeam($team, NotificationType::DiscoveryComplete, [
            'run_id' => $run->getKey(),
            'leads_created' => $run->leads_created,
            'leads_found' => $run->leads_found,
        ]);
    }

    /**
     * A notification is a courtesy, not data: losing one costs the team a nudge and nothing else,
     * so a give-up is logged for someone to notice rather than escalated.
     */
    public function failed(DiscoveryRunCompleted $event, Throwable $exception): void
    {
        Log::channel('notifications')->error('Could not announce a finished discovery run.', [
            'team_id' => $event->teamId,
            'run_id' => $event->runId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
