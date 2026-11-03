<?php

declare(strict_types=1);

namespace App\Modules\Signals\Jobs;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Actions\RunSignalPlaybooksAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puts one batch of signals through a team's playbooks, off the request (S14).
 *
 * Queued because the chain reaches a model: one drafting step is an AI call with a timeout, and a
 * watch run that waited for its own automation to finish would turn a two-minute run into a long
 * one for no benefit to anybody watching the progress bar.
 *
 * **`ShouldBeUnique` on `(team, batch)`**, where the batch is a hash of the signal ids. That is
 * deliberately not "one job per team": a team whose daily watch and whose manual run both finish
 * within the window have two genuinely different batches to handle, and a team-wide key would drop
 * the second silently. Signal ids are the unit of work, so they are the key.
 *
 * It is also **not the thing that makes a playbook fire once.** That is the
 * `(signal_id, playbook_id)` unique inside {@see RunSignalPlaybooksAction}, which holds across
 * retries, across both events that dispatch this job, and across two workers racing. Uniqueness
 * here only saves the duplicated effort — the correctness lives in the database, where a queue
 * driver's at-least-once delivery cannot reach it.
 *
 * `afterCommit`, because the listeners that dispatch it run on events raised from `DB::afterCommit`
 * already; stating it here means a future caller who forgets cannot have this job read signals its
 * own transaction has not written.
 */
final class RunSignalPlaybooksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Three, and the retry is safe: the claim row is what stops a retry re-doing work, so a second
     * attempt re-reads, finds every `(signal, playbook)` already claimed, and does nothing.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    /**
     * Generous, because the chain may include an AI draft per signal and the per-job drafting cap
     * is ten. Still bounded: a job that has been going ten minutes has met something unexpected.
     *
     * @var int
     */
    public $timeout = 600;

    /** Longer than a run of it could take, so a crashed worker cannot block the next batch. */
    public int $uniqueFor = 900;

    /**
     * @param  list<int>  $signalIds
     */
    public function __construct(
        public readonly int $teamId,
        public readonly array $signalIds,
    ) {
        // Set through Queueable's own method rather than by redeclaring `$afterCommit`, which the
        // trait already defines and PHP refuses to see redeclared with a type — the same trap
        // `TeamNotification` documents.
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        // The ids, order-independent: the same batch arriving from the detection event and from a
        // conversion event is one piece of work.
        $ids = $this->signalIds;
        sort($ids);

        return $this->teamId.':'.sha1(implode(',', $ids));
    }

    public function handle(RunSignalPlaybooksAction $action): void
    {
        $team = Team::query()->find($this->teamId);

        if (! $team instanceof Team) {
            // The tenant was deleted between the event and this job. Nothing to do and nothing
            // wrong — the signals went with it.
            return;
        }

        $result = $action->handle($team, $this->signalIds);

        // **The tail comes back.** Signals past the batch ceiling, and signals a rule could not
        // afford this time, are left unclaimed and handed to a later job — which is the whole point
        // of deferring rather than refusing. The first cut dropped them, so a 200-signal run never
        // automated anything past the cap and nothing said so (found in the S14 review).
        //
        // It terminates: every pass handles at least one signal or defers strictly fewer than it
        // received, so the list shrinks. The **delay** is what makes it useful rather than a loop —
        // the spend ceilings are per job, so the next pass needs to be a later one.
        if ($result['deferred'] !== [] && count($result['deferred']) < count($this->signalIds)) {
            self::dispatch($this->teamId, $result['deferred'])
                ->delay(now()->addSeconds(max(60, (int) config('signals.playbooks.defer_seconds', 900))));
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('signals')->error('Playbooks could not be run for a batch of signals.', [
            'team_id' => $this->teamId,
            'signals' => count($this->signalIds),
            // The **class**, not the message: a `QueryException`'s message embeds its bindings,
            // which here would be a rule's name and an article's headline. Ids and counts only
            // (CLAUDE.md §2) — the Action's own catch already does it this way.
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
