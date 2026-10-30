<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Notifications\Actions\RegisterPushDeviceAction;
use App\Modules\Notifications\Http\Requests\RegisterPushDeviceRequest;
use App\Modules\Notifications\Http\Resources\PushDeviceResource;
use App\Modules\Notifications\Models\PushDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Device registration for push (Phase 12). API-only: a device token comes from the mobile app's
 * own runtime, and there is nothing for a web form to post.
 *
 * **No team.** A phone belongs to a person, not a team — the same handset receives notifications
 * for every team its owner belongs to, and whether to send at all is the per-team preference's
 * decision. So a member who switches teams does not re-register.
 *
 * Nothing here ever returns a token. It is a bearer credential for sending *to* the device, and the
 * client already has it.
 */
final class PushDeviceController extends Controller
{
    public function store(RegisterPushDeviceRequest $request, RegisterPushDeviceAction $register): JsonResponse
    {
        $device = $register->handle($this->user($request), $request->token(), $request->platform());

        // 201 on a first registration, 200 on a re-registration — which is the common case, since
        // the app registers on every launch.
        return PushDeviceResource::make($device)
            ->response()
            ->setStatusCode($device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Signing out on a phone, or revoking notifications from its settings.
     */
    public function destroy(RegisterPushDeviceRequest $request, RegisterPushDeviceAction $register): JsonResponse
    {
        abort_unless($register->forget($this->user($request), $request->token()), 404);

        return new JsonResponse(status: 204);
    }

    /**
     * The member's registered devices — so the app can tell them push is set up, and which handset
     * it is set up on, without being handed back the tokens.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PushDeviceResource::collection(
            PushDevice::query()
                ->where('user_id', $this->user($request)->getKey())
                ->latest('last_used_at')
                ->get(),
        );
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
