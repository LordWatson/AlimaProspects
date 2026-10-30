<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Actions\MarkNotificationsReadAction;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Services\NotificationReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Notifications over the API (Phase 12) — what the mobile app's own bell reads.
 *
 * `unread` is its own endpoint rather than a count on the list, because a badge is polled far more
 * often than the list is opened and a count query is a fraction of the cost.
 */
final class NotificationController extends Controller
{
    public function index(Request $request, NotificationReader $reader): AnonymousResourceCollection
    {
        return NotificationResource::collection(
            $reader->paginate($this->user($request), $this->team($request)),
        );
    }

    public function unread(Request $request, NotificationReader $reader): JsonResponse
    {
        return new JsonResponse([
            'data' => ['unread' => $reader->unreadCount($this->user($request), $this->team($request))],
        ]);
    }

    public function read(Request $request, string $notification, MarkNotificationsReadAction $mark): JsonResponse
    {
        abort_unless(
            $mark->one($this->user($request), $this->team($request), $notification),
            404,
        );

        return new JsonResponse(status: 204);
    }

    public function readAll(Request $request, MarkNotificationsReadAction $mark): JsonResponse
    {
        $cleared = $mark->all($this->user($request), $this->team($request));

        return new JsonResponse(['data' => ['cleared' => $cleared]]);
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
