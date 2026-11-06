<?php

declare(strict_types=1);

namespace App\Modules\Signals\Listeners;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Events\SignalConverted;
use App\Modules\Signals\Events\SignalsDetected;
use App\Modules\Signals\Jobs\RunSignalPlaybooksJob;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalPlaybook;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns the two events a signal's life already raises into a playbook run (S14).
 *
 * Both existing events, and no third one: a rule should fire on a signal arriving, and on a signal
 * becoming a lead by somebody's own hand — a team whose rule says "when it becomes a lead, assign
 * it and draft an intro" means that whether the conversion came from a rule or from a click.
 *
 * **The listener resolves ids; the job does the work.** It is deliberately not queued itself. These
 * events are raised from `DB::afterCommit` and the listener's only job is to read which signals a
 * run produced and hand them on, which is one indexed query — a queued listener dispatching a
 * queued job would be two hops and one more place for a batch to be lost.
 *
 * **The `SignalConverted` case does not re-handle the conversion that a rule's own step just made.**
 * It does not need to be told not to: the `(signal_id, playbook_id)` unique inside
 * `RunSignalPlaybooksAction` has already claimed that pair, so the second pass finds the claim and
 * returns. That is worth knowing, because the alternative — a flag on the event saying who
 * converted it — would be the kind of state that is right until somebody adds a third caller.
 *
 * It also **asks whether the team has any rules at all** before dispatching. A team with no
 * playbooks is the overwhelming majority for as long as this phase is new, and a job per watch run
 * per team that does nothing is a queue nobody can read.
 */
final class RunPlaybooks
{
    public function __construct(private readonly TeamContext $teamContext) {}

    public function detected(SignalsDetected $event): void
    {
        if ($event->count < 1) {
            return;
        }

        $this->dispatchFor($event->teamId, fn (): array => array_values(
            Signal::query()
                ->where('signal_run_id', $event->runId)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        ));
    }

    public function converted(SignalConverted $event): void
    {
        $this->dispatchFor($event->teamId, static fn (): array => [$event->signalId]);
    }

    /**
     * Dispatch the job for a team, if they have rules and there is anything to run them over.
     *
     * @param  callable(): list<int>  $signals
     */
    private function dispatchFor(int $teamId, callable $signals): void
    {
        // **Nothing here may fail the thing that raised the event**, and that was a defect found in
        // review. This listener runs synchronously inside `RunSignalWatchAction`'s `afterCommit`
        // callback, so an unavailable queue or a failed read propagated into the watch-run job and
        // failed it — and the retry re-ran the Serper search and re-billed the team for it. The
        // signals are already saved by this point; automating them is worth nothing next to that.
        try {
            $team = Team::query()->find($teamId);

            if (! $team instanceof Team) {
                return;
            }

            $ids = $this->teamContext->run($team, function () use ($signals): array {
                // Cheapest possible question first: does this team automate anything?
                if (! SignalPlaybook::query()->where('is_active', true)->exists()) {
                    return [];
                }

                return $signals();
            });

            if ($ids === []) {
                return;
            }

            RunSignalPlaybooksJob::dispatch($teamId, $ids);
        } catch (Throwable $e) {
            Log::channel('signals')->error('Playbooks could not be queued for a team.', [
                'team_id' => $teamId,
                'exception' => $e::class,
            ]);
        }
    }
}
