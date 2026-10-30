<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Models\SignalWatch;

/**
 * How many watches a team has (S5/S6).
 *
 * Its own Action rather than a `count()` on {@see ListSignalWatchesAction}'s result, because the
 * feed only needs to know **whether** the team has set anything up — "no watches" is the one empty
 * state that explains why the feed is empty — and loading every watch with its run history and a
 * signal count to answer that is several queries for a number.
 */
final class CountSignalWatchesAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    public function handle(Team $team): int
    {
        return $this->teamContext->run($team, static fn (): int => SignalWatch::query()
            ->where('team_id', $team->getKey())
            ->count());
    }
}
