<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Database\Eloquent\Collection;

/**
 * The team's watches for the settings screen (S5), newest first, each with its recent run history
 * and its signal count.
 *
 * A team has a handful of watches, not a page of them, so this returns them all rather than
 * paginating — but the **runs are capped** per watch, because a daily watch left alone for a year
 * has 365 of them and the screen only ever shows the last few.
 */
final class ListSignalWatchesAction
{
    /** How many recent runs the settings screen shows per watch. */
    public const int RECENT_RUNS = 5;

    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @return Collection<int, SignalWatch>
     */
    public function handle(Team $team): Collection
    {
        return $this->teamContext->run($team, static fn (): Collection => SignalWatch::query()
            ->where('team_id', $team->getKey())
            ->with([
                'icp:id,name',
                // Capped per watch via a window over the relation, so one busy watch can't drag
                // the whole screen's query down. The newest run is what "last run" reads.
                'runs' => static fn ($query) => $query->latest('id')->limit(self::RECENT_RUNS),
            ])
            ->withCount('signals')
            ->orderByDesc('id')
            ->get());
    }
}
