<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Actions\DismissAnnouncementAction;
use App\Modules\Admin\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A member clearing an announcement banner (Phase 19b).
 *
 * **The one Admin route a customer uses**, which is why it lives on the ordinary `web` stack rather
 * than behind the admin middleware. Named `announcements.dismiss` rather than `admin.*` for the
 * same reason the impersonation stop route is: the `admin.` prefix carries the whole operator stack
 * in `AdminRouteContractTest`, and a tenant-facing route carrying that would either be refused or
 * would weaken the contract.
 *
 * Per **person**, not per team: one colleague clearing a banner must not hide it from the rest of
 * their team, who have not read it.
 */
final class AnnouncementDismissalController extends Controller
{
    public function __invoke(
        Request $request,
        Announcement $announcement,
        DismissAnnouncementAction $action,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        // A non-dismissible announcement cannot be cleared — the cross is not rendered for one, and
        // this is what makes that true rather than merely tidy.
        abort_unless($announcement->is_dismissible, 403);

        $action->handle($announcement, $user);

        return back();
    }
}
