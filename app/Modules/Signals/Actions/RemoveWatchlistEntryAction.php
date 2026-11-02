<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use Illuminate\Support\Facades\DB;

/**
 * Stops watching one account (S10).
 *
 * Small enough to look like it belongs in the controller, and it does not: removing an entry is
 * **two** writes — the row, and the list's cached `entries_count` — so it is one transaction or it
 * is a counter that drifts the first time the second write fails. The count is read back from the
 * table rather than decremented, for the same reason.
 *
 * It refuses a synced list, like {@see AddWatchlistEntriesAction} does: a name the nightly sync
 * would put straight back is not something to offer a member.
 */
final class RemoveWatchlistEntryAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws SignalsException when the list is one a job maintains.
     */
    public function handle(
        Team $team,
        User $actor,
        SignalWatchlist $watchlist,
        SignalWatchlistEntry $entry,
    ): void {
        if (! $watchlist->allowsManualEntries()) {
            throw SignalsException::watchlistIsSynced();
        }

        $this->teamContext->run($team, function () use ($actor, $watchlist, $entry): void {
            DB::transaction(function () use ($actor, $watchlist, $entry): void {
                $entry->delete();

                $watchlist->forceFill(['entries_count' => $watchlist->entries()->count()])->save();

                // The id, not the name: the audit trail has no business holding a second copy of a
                // company list (CLAUDE.md §2).
                $this->audit->log('signals.watchlist_entry_removed', $watchlist, [
                    'signal_watchlist_entry_id' => (int) $entry->getKey(),
                    'entries' => (int) $watchlist->entries_count,
                ], $actor);
            });
        });
    }
}
