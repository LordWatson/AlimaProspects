<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Models\Announcement;
use Illuminate\Support\Facades\DB;

/**
 * One person clears one banner (Phase 19b).
 *
 * Per **person**, not per team: one colleague clearing a notice must not hide it from the four
 * people who have not read it.
 *
 * The one thing worth saying about such a small write is why it is an Action at all — CLAUDE.md §2
 * puts every write in a transaction at the Action level, and "it is only a pivot row" is how a
 * codebase ends up with two rules. It is also the only write in the panel a **tenant** performs
 * rather than an operator, so it is deliberately not audited: an audit row per banner dismissal per
 * member would bury the operator decisions the log exists to hold.
 */
final class DismissAnnouncementAction
{
    public function handle(Announcement $announcement, User $user): void
    {
        DB::transaction(static function () use ($announcement, $user): void {
            // `syncWithoutDetaching`, so a double-click is one row rather than a unique-constraint
            // violation on a perfectly reasonable action. The timestamp is passed explicitly
            // because the pivot carries `created_at` alone — see {@see Announcement::dismissedBy()}.
            $announcement->dismissedBy()->syncWithoutDetaching([
                $user->getKey() => ['created_at' => now()],
            ]);
        });
    }
}
