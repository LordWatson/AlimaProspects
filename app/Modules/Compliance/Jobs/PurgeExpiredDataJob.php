<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Jobs;

use App\Modules\Compliance\Actions\PurgeExpiredDataAction;
use App\Modules\Compliance\Console\PurgeExpiredDataCommand;
use App\Modules\Core\Models\Team;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One team's retention sweep, on the queue (docs/compliance.md §3.4). Dispatched per team by
 * {@see PurgeExpiredDataCommand}, which runs nightly.
 *
 * **Queued per team rather than one job for the platform**, so a team with a very large backlog
 * cannot starve every other team's sweep, and a failure on one team's data is one team's failure.
 *
 * **`ShouldBeUnique` per team** because the one thing that must not happen is two sweeps racing
 * over the same leads: the delete branch reads ids and then deletes them, and two passes doing that
 * concurrently would have one of them deleting rows the other had already taken. The unique lock is
 * held well past the job's own timeout so it cannot expire mid-run.
 *
 * **Idempotent.** The delete branch's candidates stop matching once they are gone; the anonymise
 * branch filters on `anonymised_at` being null. A retried job finishes the work rather than redoing
 * it, which is what makes `$tries` safe on a job that deletes things.
 */
final class PurgeExpiredDataJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @var array<int, int>
     */
    public array $backoff = [120];

    /** Long: a first sweep over a team with years of leads does real work. */
    public int $timeout = 900;

    /** Comfortably past `$timeout`, so the lock cannot lapse while the job is still running. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $teamId) {}

    public function uniqueId(): string
    {
        return 'compliance-purge:'.$this->teamId;
    }

    public function handle(PurgeExpiredDataAction $purge): void
    {
        $team = Team::query()->find($this->teamId);

        if (! $team instanceof Team) {
            // The team was deleted between the command dispatching and this running. Its data went
            // with it on the cascade, so there is nothing left to sweep.
            return;
        }

        $purge->handle($team);
    }

    public function failed(Throwable $exception): void
    {
        // The message, not the data. A failed retention sweep is a promise unkept, so it is logged
        // at error even though nothing a member did caused it.
        Log::channel('compliance')->error('Retention sweep failed.', [
            'team_id' => $this->teamId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
