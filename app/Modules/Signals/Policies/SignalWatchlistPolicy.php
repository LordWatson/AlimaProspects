<?php

declare(strict_types=1);

namespace App\Modules\Signals\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Signals\Models\SignalWatchlist;

/**
 * Ownership double-check for watchlists (ADR 0006).
 *
 * The same shape as {@see SignalWatchPolicy}, and for the same reason: any member — including
 * Read-only — may see which accounts the team watches, because that is the team's business, and
 * Owners, Admins and Members may change them. Read-only may not. A non-member always fails.
 *
 * `sync` is a write even though it creates nothing of its own: it rewrites the list's entries from
 * the pipeline, and a Read-only member pressing it would be changing what the team pays to search.
 */
final class SignalWatchlistPolicy
{
    public function view(User $user, SignalWatchlist $watchlist): bool
    {
        return $user->belongsToTeam($watchlist->team);
    }

    public function viewAny(User $user, SignalWatchlist $watchlist): bool
    {
        return $user->belongsToTeam($watchlist->team);
    }

    public function create(User $user, SignalWatchlist $watchlist): bool
    {
        return $this->canManage($user, $watchlist);
    }

    public function update(User $user, SignalWatchlist $watchlist): bool
    {
        return $this->canManage($user, $watchlist);
    }

    public function delete(User $user, SignalWatchlist $watchlist): bool
    {
        return $this->canManage($user, $watchlist);
    }

    public function sync(User $user, SignalWatchlist $watchlist): bool
    {
        return $this->canManage($user, $watchlist);
    }

    private function canManage(User $user, SignalWatchlist $watchlist): bool
    {
        $role = $user->roleOn($watchlist->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin, TeamRole::Member], strict: true);
    }
}
