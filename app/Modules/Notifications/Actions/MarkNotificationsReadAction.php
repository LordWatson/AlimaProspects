<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Marks notifications read (Phase 12).
 *
 * Scoped through {@see Notification::scopeOwnedBy()} in every query, because {@see Notification}
 * has no tenant global scope (see the model's note) and "mark all read" is exactly the operation
 * that would do the most damage if it reached across teams — a member clearing their bell in one
 * team would clear what they had not yet seen in another. The condition lives on the model rather
 * than being written out here, so it cannot drift from the reader's copy of it.
 */
final class MarkNotificationsReadAction
{
    /**
     * One notification. Returns false when it isn't this member's or isn't in this team, so the
     * caller can 404 rather than silently succeed.
     */
    public function one(User $user, Team $team, string $id): bool
    {
        $notification = Notification::query()->ownedBy($user, $team)->whereKey($id)->first();

        if (! $notification instanceof Notification) {
            return false;
        }

        // Already read is a success, not a no-op worth reporting: two tabs marking the same row is
        // the normal case.
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return true;
    }

    /**
     * Everything unread for this member in this team. Returns how many were cleared.
     */
    public function all(User $user, Team $team): int
    {
        return DB::transaction(static fn (): int => Notification::query()
            ->ownedBy($user, $team)
            ->unread()
            ->update(['read_at' => now()]));
    }
}
