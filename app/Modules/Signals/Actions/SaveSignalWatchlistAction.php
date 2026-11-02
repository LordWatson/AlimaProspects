<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Data\SignalWatchlistData;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalWatchlist;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates one watchlist (S10), transactionally and audited.
 *
 * Three things worth knowing:
 *
 * - **A team cannot create lists without limit.** Every list is searched by every watch pointed at
 *   it, and every entry on it is a paid search — so the ceiling is on creating them
 *   (`signals.watchlist.max_lists_per_team`) as well as on their size. The same reasoning as the
 *   watch and definition ceilings one level up.
 * - **`source_kind` is set on create and never changed.** A manual list holds names somebody typed
 *   and a pipeline list holds an output; turning one into the other would either delete work
 *   somebody did by hand or adopt their typing as a sync result. Changing their mind means a new
 *   list, which costs them nothing.
 * - **A newly created pipeline list is synced immediately**, after commit, rather than waiting for
 *   the nightly job. A member who just pointed Alima at their pipeline should see it populated, not
 *   an empty list with a promise.
 */
final class SaveSignalWatchlistAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
        private readonly SyncPipelineWatchlistAction $sync,
    ) {}

    /**
     * @throws SignalsException when the team is at its list ceiling.
     */
    public function handle(
        Team $team,
        User $actor,
        SignalWatchlistData $data,
        ?SignalWatchlist $watchlist = null,
    ): SignalWatchlist {
        $saved = $this->teamContext->run($team, fn (): SignalWatchlist => DB::transaction(function () use ($team, $actor, $data, $watchlist): SignalWatchlist {
            $attributes = $data->toAttributes();

            if ($watchlist instanceof SignalWatchlist) {
                // The kind is immutable — see the class docblock. Dropped here rather than refused,
                // because a form that posts the whole object should not fail on a field it cannot
                // change; the stored value simply wins.
                unset($attributes['source_kind']);

                $watchlist->forceFill($attributes)->save();

                $this->audit->log('signals.watchlist_updated', $watchlist, [
                    'name' => $watchlist->name,
                    'is_active' => $watchlist->is_active,
                ], $actor);

                return $watchlist;
            }

            $this->assertRoomForAnother($team);

            /** @var SignalWatchlist $created */
            $created = SignalWatchlist::query()->create([
                ...$attributes,
                'team_id' => $team->getKey(),
                'created_by' => $actor->getKey(),
                'entries_count' => 0,
            ]);

            $this->audit->log('signals.watchlist_created', $created, [
                'name' => $created->name,
                'source_kind' => $created->source_kind->value,
            ], $actor);

            return $created;
        }));

        // After commit, and only for a list that syncs: the member asked for their pipeline to be
        // watched, so it is watched now rather than tomorrow. Inside its own transaction, because
        // the sync is idempotent and a failure here must not undo the list itself.
        if ($watchlist === null && $saved->isSyncable()) {
            $this->sync->handle($team, $saved);
            $saved->refresh();
        }

        return $saved;
    }

    /**
     * Refuse a new list once the team is at its ceiling.
     *
     * Checked on **create only** — an existing list is already counted, so editing one must not
     * fail because the team is at the limit.
     *
     * @throws SignalsException when the team has no room.
     */
    private function assertRoomForAnother(Team $team): void
    {
        $max = max(1, (int) config('signals.watchlist.max_lists_per_team', 5));

        if (SignalWatchlist::query()->where('team_id', $team->getKey())->count() >= $max) {
            throw SignalsException::tooManyWatchlists($max);
        }
    }
}
