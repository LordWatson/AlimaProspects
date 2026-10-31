<?php

declare(strict_types=1);

namespace App\Modules\Admin\Jobs;

use App\Modules\Admin\Models\Announcement;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Notifies the tenants an announcement targets (Phase 19b).
 *
 * **Aggregated, in the `AnnounceTopLeadsJob` shape**: one notification per *team*, raised through
 * `NotificationDispatcher::toTeam()`, which is what fans it out to that team's members according to
 * each member's own preferences. The thing this must never become is a loop over every member of
 * every tenant — a thousand customers with four colleagues each is four thousand rows written one
 * at a time, from a job that also has to not time out.
 *
 * Queued, because it walks every team: an announcement published from a browser must not hold the
 * request open while it does.
 *
 * **Idempotent per tenant, not per job.** `ShouldBeUnique` stops a double-saved form from queueing
 * two fan-outs, and `announcements.notified_at` stops a retry of a job that finished. Neither helps
 * with the case that actually happens: this job walks every team, so if it dies at team 3,000 of
 * 4,000 the retry starts again at team 1. The claim is therefore written per team, into
 * `announcement_notifications`, whose unique index is what makes it a guard rather than a note —
 * the insert is the claim, so two workers racing the same chunk cannot both send. Telling every
 * customer twice is the failure mode that would make the next announcement something people filter.
 */
final class AnnounceToTenantsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $announcementId) {}

    public function uniqueId(): string
    {
        return (string) $this->announcementId;
    }

    public function handle(NotificationDispatcher $notifications, PlanResolver $plans): void
    {
        $announcement = Announcement::query()->find($this->announcementId);

        if (! $announcement instanceof Announcement) {
            return;
        }

        // Already sent. The column is set before the queue is reached in the happy path, so this is
        // the guard against a retry of a job whose notifications did go out.
        if ($announcement->notified_at !== null) {
            Log::channel('notifications')->info('Announcement already notified; skipping.', [
                'announcement_id' => $announcement->getKey(),
            ]);

            return;
        }

        $notified = 0;

        // Chunked, because this is the one job in the product that touches every tenant.
        Team::query()->chunkById(200, function ($teams) use ($announcement, $notifications, $plans, &$notified): void {
            foreach ($teams as $team) {
                $planKey = $plans->planFor($team)->key->value;

                if (! $announcement->targets($team, $planKey)) {
                    continue;
                }

                // The copy in the **team's** language, not the operator's. A UAE tenant reading an
                // English notification because an operator wrote it in English is exactly what the
                // per-locale columns exist to avoid. `?: 'en'` because the column is nullable and
                // `copyFor('')` would skip straight to the fallback chain.
                $copy = $announcement->copyFor($team->locale ?: 'en');

                if ($copy['title'] === '') {
                    continue;
                }

                // **Claim the tenant before sending.** The unique index refuses a second claim, so
                // a retry that re-walks the first 2,999 teams writes nothing for them. Claiming
                // first rather than recording after is the order that fails safe: a crash between
                // the two loses one notification, where the other order sends one twice.
                if (! $this->claim($announcement, $team)) {
                    continue;
                }

                $notifications->toTeam($team, NotificationType::Announcement, [
                    'announcement_id' => $announcement->getKey(),
                    'title' => $copy['title'],
                    'body' => $copy['body'],
                ]);

                $notified++;
            }
        });

        $announcement->forceFill(['notified_at' => now()])->save();

        Log::channel('notifications')->info('Announcement notified.', [
            'announcement_id' => $announcement->getKey(),
            'teams' => $notified,
        ]);
    }

    /**
     * Claim this tenant, or report that somebody already has.
     *
     * `insertOrIgnore` rather than a read then a write: the read-then-write version has a window
     * two workers can both pass through, and this job's whole point is that it runs over every
     * tenant at once.
     */
    private function claim(Announcement $announcement, Team $team): bool
    {
        return DB::table('announcement_notifications')->insertOrIgnore([
            'announcement_id' => $announcement->getKey(),
            'team_id' => $team->getKey(),
            'created_at' => now(),
        ]) === 1;
    }

    public function failed(?\Throwable $e): void
    {
        Log::channel('notifications')->error('Announcement fan-out failed.', [
            'announcement_id' => $this->announcementId,
            'error' => $e?->getMessage(),
        ]);
    }
}
