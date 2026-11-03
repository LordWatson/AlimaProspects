<?php

declare(strict_types=1);

namespace App\Modules\Signals\Console;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Jobs\SyncPipelineWatchlistsJob;
use App\Modules\Signals\Models\SignalSourceStat;
use App\Modules\Signals\Services\SignalTuningAdvisor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Look at what each team has been doing and leave them some suggestions (S12).
 *
 * **Weekly, not nightly**, and that is a product decision rather than a performance one. The
 * evidence a proposal rests on accumulates over weeks — eight dismissals of one outlet is a
 * fortnight's feed for most teams — and a panel that re-proposed every morning would be
 * indistinguishable from nagging. Once a week is often enough that a pattern is caught while it
 * still matters and rare enough that the panel is worth opening.
 *
 * It only visits teams that have **something to learn from**: a team with no source statistics has
 * never dismissed anything, and running the advisor over them would be a query per team per week
 * to reach the same empty answer.
 *
 * One team's failure does not stop the rest, which is the same arrangement
 * {@see SyncPipelineWatchlistsJob} makes for the same reason.
 */
final class AdviseSignalTuningCommand extends Command
{
    protected $signature = 'signals:advise {--team= : One team id, rather than every team}';

    protected $description = 'Propose tuning changes from what each team has kept and dismissed';

    public function __construct(
        private readonly SignalTuningAdvisor $advisor,
        private readonly TeamContext $teamContext,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $only = $this->option('team');
        $teams = $this->teams(is_numeric($only) ? (int) $only : null);

        $advised = 0;
        $open = 0;

        foreach ($teams as $team) {
            try {
                // Inside the team's context, so every read the advisor makes is scoped by the
                // global scope as well as by the explicit `team_id` it passes.
                $open += $this->teamContext->run($team, fn (): int => $this->advisor->advise($team));
                $advised++;
            } catch (Throwable $e) {
                // One team's bad data is not a reason to leave every other team unadvised.
                Log::channel('signals')->warning('Signal tuning advice failed for a team.', [
                    'team_id' => (int) $team->getKey(),
                    'error' => $e::class,
                ]);
            }
        }

        $this->info(sprintf('Advised %d team(s); %d suggestion(s) are open.', $advised, $open));

        return self::SUCCESS;
    }

    /**
     * The teams worth advising: the ones that have told the product something.
     *
     * @return list<Team>
     */
    private function teams(?int $only): array
    {
        /** @var list<int> $withEvidence */
        $withEvidence = SignalSourceStat::query()
            ->withoutGlobalScopes()
            ->when($only !== null, static fn ($query) => $query->where('team_id', $only))
            ->distinct()
            ->orderBy('team_id')
            ->pluck('team_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($withEvidence === []) {
            return [];
        }

        /** @var list<Team> $teams */
        $teams = Team::query()->whereIn('id', $withEvidence)->orderBy('id')->get()->all();

        return $teams;
    }
}
