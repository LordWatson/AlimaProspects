<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Jobs;

use App\Modules\AiInsights\Services\InsightGenerator;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates one team's insights for the day (Phase 14), via {@see InsightGenerator}, inside that
 * team's {@see TeamContext}.
 *
 * `ShouldBeUnique` per team, so the hourly sweep and a manual `insights:generate --team=` cannot
 * run the same team twice at once — which matters because the run spends AI credit. A retry is
 * safe on top of that: the generator's dedupe key means a second successful run on the same day
 * creates nothing and notifies nobody (see `StoreInsightsAction`).
 *
 * A transient failure bubbles so the queue retries with backoff. There is no "failed" row to mark
 * — unlike a signal run, an insight run has no record of its own, which is deliberate: the one
 * durable artefact is the insights it creates, and a run that produced none has nothing worth
 * keeping.
 */
final class GeneratePeriodicInsightsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * Release the uniqueness lock after ten minutes so a crashed worker can't block tomorrow's run.
     *
     * @var int
     */
    public $uniqueFor = 600;

    /**
     * Kept below the queue's `retry_after` (90s) so a long run can't be dispatched twice, matching
     * the other AI jobs. The work is bounded: a couple of dozen aggregates and one AI call.
     *
     * @var int
     */
    public $timeout = 85;

    /**
     * @var list<int>
     */
    public $backoff = [30, 120, 300];

    /**
     * @param  bool  $force  Run even though the team has already been done today — the
     *                       `insights:generate --team=` path. The insights' dedupe keys and the
     *                       generator's "already have today's digest" check are what keep a forced
     *                       run from duplicating either a row or a cost.
     */
    public function __construct(public readonly int $teamId, public readonly bool $force = false) {}

    public function uniqueId(): string
    {
        return (string) $this->teamId;
    }

    public function handle(InsightGenerator $generator, TeamContext $teamContext): void
    {
        $team = Team::query()->find($this->teamId);

        // The team was deleted between the sweep and the worker picking this up.
        if (! $team instanceof Team) {
            return;
        }

        $teamContext->run($team, function () use ($generator, $team): void {
            Log::withContext(['team_id' => $this->teamId]);

            $generator->forTeam($team, force: $this->force);
        });
    }

    public function failed(Throwable $exception): void
    {
        // The exception class, not its message: a provider failure's message can carry prompt
        // content, and this channel holds ids and counts.
        Log::channel('insights')->error('Insight generation failed.', [
            'team_id' => $this->teamId,
            'exception' => $exception::class,
        ]);
    }
}
