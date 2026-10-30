<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Actions\MarkNotificationsReadAction;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Services\NotificationReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The notification history and the read/unread writes (Phase 12).
 *
 * The **bell** does not use these at all — its contents are a shared Inertia prop, so it is already
 * on the page wherever the member is (see `NotificationsServiceProvider`). This is the full list
 * behind it, and the two endpoints that mark things read.
 *
 * No policy. Authorisation here is the query: a notification is reachable only through the
 * member-and-team condition in {@see NotificationReader}/{@see MarkNotificationsReadAction}, so
 * somebody else's notification is a 404 rather than a 403 — which is also the right answer, since
 * they have no business learning it exists.
 */
final class NotificationController extends Controller
{
    public function index(Request $request, NotificationReader $reader): Response
    {
        $user = $this->user($request);
        $team = $this->team($request);

        return Inertia::render('notifications/Index', [
            // The whole paginator, so the screen gets `links` and `meta` for the shared pager —
            // the same shape the Signals feed uses.
            'notifications' => NotificationResource::collection($reader->paginate($user, $team))
                ->toResponse($request)
                ->getData(true),
            'unreadCount' => $reader->unreadCount($user, $team),
        ]);
    }

    public function read(Request $request, string $notification, MarkNotificationsReadAction $mark): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        abort_unless($mark->one($user, $team, $notification), 404);

        return back();
    }

    public function readAll(Request $request, MarkNotificationsReadAction $mark): RedirectResponse
    {
        $mark->all($this->user($request), $this->team($request));

        return back();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function team(Request $request): Team
    {
        /** @var Team $team */
        $team = $this->user($request)->currentTeam()->firstOrFail();

        return $team;
    }
}
