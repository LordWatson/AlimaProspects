<?php

declare(strict_types=1);

namespace App\Modules\Signals\Listeners;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Events\SignalConverted;
use App\Modules\Signals\Events\SignalDismissed;
use App\Modules\Signals\Events\SignalRestored;
use App\Modules\Signals\Events\SignalsDetected;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Services\SignalSourceLedger;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records what became of a signal, so the product can learn which outlets are worth reading (S12).
 *
 * **A listener rather than a line in each Action**, which is the arrangement CLAUDE.md asks for
 * and which earns its keep here for a specific reason: a tally is the most skippable write in the
 * product. If updating it ever fails, the member's dismissal must still have happened — they
 * pressed a button and the card has to go. So this runs **after commit**, catches everything, and
 * logs rather than throws. A statistic lost is a statistic; a dismissal lost is a bug report.
 *
 * It listens to four events because there are four outcomes, and the asymmetry between them is the
 * interesting part. A **keep** is per *card*: it reads the run's representatives (S11), because a
 * member only ever actions the primary and counting keeps per article while counting dismissals
 * per card would make every well-covered outlet look beloved. A **restore** decrements, so a
 * mis-click does not leave a lesson behind.
 *
 * Nothing here is queued. The writes are one statement each and the alternative — a job per
 * dismissal — would be a queue entry per click for a counter nobody reads in real time.
 */
final class RecordSignalOutcome
{
    public function __construct(
        private readonly SignalSourceLedger $ledger,
        private readonly TeamContext $teamContext,
    ) {}

    /**
     * A run kept some signals: credit each representative's outlet.
     */
    public function keep(SignalsDetected $event): void
    {
        $this->guard($event->teamId, function () use ($event): void {
            /** @var list<Signal> $kept */
            $kept = Signal::query()
                ->where('team_id', $event->teamId)
                ->where('signal_run_id', $event->runId)
                ->representatives()
                ->get(['id', 'team_id', 'source_name', 'event_key', 'signal_definition_id'])
                ->all();

            $this->ledger->recordKept($kept);
        });
    }

    public function dismiss(SignalDismissed $event): void
    {
        $this->guard($event->teamId, function () use ($event): void {
            $signal = $this->signal($event->teamId, $event->signalId);

            if ($signal instanceof Signal) {
                $this->ledger->recordDismissed($signal, $event->reason);
            }
        });
    }

    public function restore(SignalRestored $event): void
    {
        $this->guard($event->teamId, function () use ($event): void {
            $signal = $this->signal($event->teamId, $event->signalId);

            if ($signal instanceof Signal) {
                $this->ledger->recordRestored($signal, $event->reason);
            }
        });
    }

    public function convert(SignalConverted $event): void
    {
        $this->guard($event->teamId, function () use ($event): void {
            $signal = $this->signal($event->teamId, $event->signalId);

            if ($signal instanceof Signal) {
                $this->ledger->recordConverted($signal);
            }
        });
    }

    /**
     * One signal, scoped twice.
     *
     * The explicit `team_id` is not decoration: the event carries both ids and a listener running
     * outside a team context would otherwise read any tenant's row by id, then credit the outlet
     * on **this** team's ledger.
     */
    private function signal(int $teamId, int $signalId): ?Signal
    {
        return Signal::query()
            ->where('team_id', $teamId)
            ->whereKey($signalId)
            ->first(['id', 'team_id', 'source_name', 'event_key', 'signal_definition_id']);
    }

    /**
     * Run inside the team's context, and never let a bookkeeping failure reach the member.
     *
     * The context matters because the triggering Action may have been a queued run with none set,
     * and `BelongsToTeam`'s global scope applies nothing when `TeamContext::id()` is null — the
     * explicit `where` above is what actually scopes the read, and this is what keeps any write
     * consistent with it.
     */
    private function guard(int $teamId, callable $work): void
    {
        try {
            $team = Team::query()->find($teamId);

            if ($team === null) {
                return;
            }

            $this->teamContext->run($team, $work);
        } catch (Throwable $e) {
            // Counts only, and the class of failure — never the article, never the member.
            Log::channel('signals')->warning('Could not record a signal outcome for source statistics.', [
                'team_id' => $teamId,
                'error' => $e::class,
            ]);
        }
    }
}
