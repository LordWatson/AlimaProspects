<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Announces new strongly-matching leads — **once per batch, with a count** (Phase 12).
 *
 * This job exists entirely because the obvious implementation is unusable. A discovery run can
 * score fifty leads in a minute, and "notify on each A grade" would mean fifty notifications, fifty
 * emails and a member who switches the whole category off before lunch. The aggregation is the
 * feature.
 *
 * How it works: `AnnounceTopLeads` dispatches this with a short delay whenever a lead is graded A.
 * `ShouldBeUnique` **per team** means the next forty-nine dispatches in that window find a job
 * already queued and do nothing. When it finally runs it counts what was graded A across the whole
 * window and sends one notification naming a couple of the companies.
 *
 * The window is also why the job reads from the database rather than carrying the leads: the lead
 * it was dispatched for is almost never the only one by the time it runs.
 */
final class AnnounceTopLeadsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public int $timeout = 60;

    /**
     * How long the unique lock is held. Comfortably longer than the delay the listener sets, so the
     * lock cannot expire while the job is still sitting in the queue and let a second one through.
     */
    public int $uniqueFor = 1800;

    public function __construct(public readonly int $teamId) {}

    public function uniqueId(): string
    {
        return 'announce-top-leads:'.$this->teamId;
    }

    public function handle(
        TeamContext $teamContext,
        NotificationDispatcher $dispatcher,
    ): void {
        $team = Team::query()->find($this->teamId);

        if (! $team instanceof Team) {
            return;
        }

        $since = now()->subMinutes($this->windowMinutes());

        $teamContext->run($team, function () use ($team, $dispatcher, $since): void {
            // Graded A in the window, against the team's **active** ICP — a score against a
            // retired profile is not news. Newest first, so the names shown are the latest.
            $scores = LeadScore::query()
                ->where('team_id', $team->getKey())
                ->where('grade', ScoreGrade::A->value)
                ->where('scored_at', '>=', $since)
                ->whereHas('icp', fn ($query) => $query->where('is_active', true))
                ->with('lead:id,company_name')
                ->orderByDesc('scored_at')
                ->limit(50)
                ->get();

            if ($scores->isEmpty()) {
                // The window closed with nothing in it — a re-score that went from A to B, or a
                // lead deleted. Nothing to announce, and announcing "0 matches" would be worse
                // than silence.
                return;
            }

            $names = $scores
                ->map(static fn (LeadScore $score): ?Lead => $score->lead)
                ->filter()
                ->map(static fn (Lead $lead): string => (string) $lead->company_name)
                ->unique()
                ->take(2)
                ->values()
                ->all();

            $dispatcher->toTeam($team, NotificationType::TopLeads, [
                'count' => $scores->count(),
                'names' => $names,
            ]);
        });
    }

    /**
     * The window the count covers — the same number the listener delays by, read from config so the
     * two cannot drift apart.
     */
    private function windowMinutes(): int
    {
        return max(1, (int) config('notifications.top_leads_window_minutes', 15));
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('notifications')->error('Could not announce new strong matches.', [
            'team_id' => $this->teamId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
