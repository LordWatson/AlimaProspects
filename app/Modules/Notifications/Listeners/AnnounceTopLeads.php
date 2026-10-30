<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Jobs\AnnounceTopLeadsJob;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Events\LeadScored;

/**
 * Queues the aggregated "new strong matches" announcement when a lead is graded A (Phase 12).
 *
 * **Not queued itself**, and doing almost nothing: all it does is dispatch
 * {@see AnnounceTopLeadsJob} with a delay. That job is `ShouldBeUnique` per team, so the fifty
 * dispatches a discovery run produces collapse into one queued job — which is the whole point, and
 * only works if the dispatch is cheap enough to happen fifty times.
 *
 * The delay is what gives the window something to aggregate. Firing immediately would announce the
 * first A-grade lead on its own and then be locked out of mentioning the other forty-nine.
 */
final class AnnounceTopLeads
{
    public function handle(LeadScored $event): void
    {
        if ($event->grade !== ScoreGrade::A->value) {
            return;
        }

        AnnounceTopLeadsJob::dispatch($event->teamId)
            ->delay(now()->addMinutes(max(1, (int) config('notifications.top_leads_window_minutes', 15))));
    }
}
