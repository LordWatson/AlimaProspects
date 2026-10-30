<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads a member's notifications for one team (Phase 12).
 *
 * Every read in the module goes through here, and the condition itself lives on the model as
 * {@see Notification::scopeOwnedBy()} — because {@see Notification} deliberately has **no tenant
 * global scope** (the rows are written by the framework, outside our team context), so that
 * condition *is* the scoping rather than a convenience on top of one. A query written by hand
 * somewhere else is a query that can forget it, and a member of three teams would then see all
 * three in one bell.
 */
final class NotificationReader
{
    /**
     * The recent notifications for the bell, newest first.
     *
     * @return Collection<int, Notification>
     */
    public function recent(User $user, Team $team, ?int $limit = null): Collection
    {
        return $this->scoped($user, $team)
            ->latest('created_at')
            ->limit($limit ?? max(1, (int) config('notifications.bell_limit', 15)))
            ->get();
    }

    public function unreadCount(User $user, Team $team): int
    {
        return $this->scoped($user, $team)->unread()->count();
    }

    /**
     * A page of the full history.
     *
     * @return LengthAwarePaginator<int, Notification>
     */
    public function paginate(User $user, Team $team, int $perPage = 25): LengthAwarePaginator
    {
        return $this->scoped($user, $team)->latest('created_at')->paginate($perPage);
    }

    /**
     * @return Builder<Notification>
     */
    private function scoped(User $user, Team $team): Builder
    {
        return Notification::query()->ownedBy($user, $team);
    }
}
