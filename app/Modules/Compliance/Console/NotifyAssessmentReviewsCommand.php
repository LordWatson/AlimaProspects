<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Console;

use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Tells a team their lawful-basis assessment is due for review, **before** lead discovery stops
 * (Phase 19b).
 *
 * The gap `compliance-privacy.md` called a "producer gap, inverted": the compliance screen says the
 * review is due and `LegitimateInterestGate` closes when it lapses, but nothing ever *told* anybody
 * — so a team that had stopped visiting the compliance centre found out when their next discovery
 * run was refused. The gate was working perfectly and the experience was indefensible.
 *
 * Two notifications, not one: a warning while there is still time to act, and one when the gate has
 * actually closed. The first is the point of the command; the second exists because somebody who
 * ignored the warning needs to know *why* sourcing stopped rather than filing a bug.
 *
 * **Idempotent per record per notice**, through `lia_records.review_notified_at`. The first version
 * had no column and fired only on the *exact* day the threshold was crossed, which made the notice
 * depend on the scheduler having run that morning: a missed day meant the team was never warned,
 * and then discovery stopped for a reason nobody had told them. Widening the window without the
 * column would have sent the warning every day for a fortnight, which is how a team learns to
 * ignore the one that matters.
 *
 * One column covers both notices because they can be told apart by comparing it against
 * `review_due_at`: a stamp **before** the due date is the warning, a stamp after it is the lapse
 * notice. Re-signing moves `review_due_at` forward and the cycle starts again with nothing to reset.
 */
final class NotifyAssessmentReviewsCommand extends Command
{
    protected $signature = 'compliance:notify-reviews
                            {--days=14 : How far ahead to warn}';

    protected $description = 'Warn teams whose lawful-basis assessment is due for review';

    public function handle(
        NotificationDispatcher $notifications,
        TeamContext $teamContext,
    ): int {
        $days = max(1, (int) $this->option('days'));
        $now = CarbonImmutable::now('UTC');
        $warned = 0;

        // The records whose review falls due within the window, or has already. Read without the
        // tenant scope because this runs from the scheduler with no team in context — and with an
        // explicit `whereNotNull`, because a record with no review date is one that never needs one.
        LiaRecord::query()
            ->withoutGlobalScopes()
            ->whereNull('withdrawn_at')
            ->whereNotNull('review_due_at')
            ->where('review_due_at', '<=', $now->addDays($days))
            // The team eager-loaded rather than fetched per record: this runs over every tenant's
            // assessment, and `find()` in the loop was a query per row.
            ->with('team')
            ->chunkById(200, function ($records) use ($notifications, $teamContext, $now, $days, &$warned): void {
                foreach ($records as $record) {
                    $team = $record->team;

                    if (! $team instanceof Team) {
                        continue;
                    }

                    $dueAt = CarbonImmutable::instance($record->review_due_at)->utc();
                    $remaining = (int) ceil($now->diffInDays($dueAt, false));
                    $notifiedAt = $record->review_notified_at === null
                        ? null
                        : CarbonImmutable::instance($record->review_notified_at)->utc();

                    if (! $this->isDue($remaining, $days, $dueAt, $notifiedAt)) {
                        continue;
                    }

                    $teamContext->run($team, function () use ($team, $notifications, $remaining): void {
                        // Owners and Admins: it is a legal record signed by a named person, and a
                        // Member cannot complete it.
                        $notifications->toAdmins($team, NotificationType::AssessmentReviewDue, [
                            'days_remaining' => max(0, $remaining),
                        ]);
                    });

                    // Stamped **after** the send, so a crash between the two re-sends rather than
                    // silently skips. A duplicate notice is a nuisance; a missing one is the
                    // failure this command exists to fix.
                    $record->forceFill(['review_notified_at' => $now])->save();

                    $warned++;
                }
            });

        Log::channel('compliance')->info('Assessment review notices sent.', [
            'teams' => $warned,
            'window_days' => $days,
        ]);

        $this->info($warned.' team(s) notified.');

        return self::SUCCESS;
    }

    /**
     * Whether this record is owed a notice right now.
     *
     * **Two notices per cycle, each sent once.** The warning while there is still time to act, and
     * one when the gate has actually closed — the second exists because somebody who ignored the
     * first needs to know *why* sourcing stopped rather than filing a bug against discovery.
     *
     * The window is `<=` rather than `===` so a day the scheduler missed does not lose the notice;
     * `review_notified_at` is what stops that becoming a daily reminder.
     */
    private function isDue(int $remaining, int $days, CarbonImmutable $dueAt, ?CarbonImmutable $notifiedAt): bool
    {
        // Lapsed. Owed unless we have already said so *since* it lapsed — a stamp from the warning
        // is earlier than the due date, so it does not count as having told them.
        if ($remaining <= 0) {
            return $notifiedAt === null || $notifiedAt->isBefore($dueAt);
        }

        // Inside the warning window, and nothing said yet this cycle.
        return $remaining <= $days && $notifiedAt === null;
    }
}
