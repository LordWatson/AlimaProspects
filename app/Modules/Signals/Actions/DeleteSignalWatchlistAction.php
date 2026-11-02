<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Signals\Models\SignalWatchlist;
use Illuminate\Support\Facades\DB;

/**
 * Removes a watchlist and its entries (S10).
 *
 * **A watch pointed at it is not deleted.** The FK is `nullOnDelete`, so such a watch becomes
 * topical again — it keeps its runs, and the signals they found keep their provenance. Deleting the
 * watch instead would take a month of history with it to tidy up a list, and a watch that now
 * searches a place instead of an account is visible on its own card; a watch that vanished is not.
 *
 * Unlike a signal **definition**, there is no in-use refusal. A definition is what a signal *was*,
 * so deleting one in use would quietly change what a watch looks for; a watchlist is only *where* to
 * look, and a team that wants to stop watching accounts should not have to unpick their watches
 * first.
 */
final class DeleteSignalWatchlistAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return int how many watches searched this list and now search places again — returned
     *             rather than re-counted by the caller, because a query in a controller is a
     *             query the Action has already run (CLAUDE.md §2).
     */
    public function handle(SignalWatchlist $watchlist, User $actor): int
    {
        $watches = $watchlist->watches()->count();
        $entries = (int) $watchlist->entries_count;

        DB::transaction(function () use ($watchlist, $actor, $watches, $entries): void {
            // Counts, not names. The entries go with it by cascade.
            $this->audit->log('signals.watchlist_deleted', $watchlist, [
                'name' => $watchlist->name,
                'entries' => $entries,
                'watches_made_topical' => $watches,
            ], $actor);

            $watchlist->delete();
        });

        return $watches;
    }
}
