<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Models\User;
use App\Modules\Notifications\Enums\PushPlatform;
use App\Modules\Notifications\Models\PushDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registers (or re-registers) a device for push (Phase 12).
 *
 * **Idempotent on the token, and it re-assigns.** FCM re-issues a token when the app is reinstalled
 * or restored to a different handset, and the same token can legitimately turn up for a *different
 * user* — a shared tablet, or a phone handed on. So an existing row is updated rather than
 * duplicated, including its `user_id`: leaving the old owner on it would send the new owner's
 * notifications to the previous one.
 */
final class RegisterPushDeviceAction
{
    public function handle(User $user, string $token, PushPlatform $platform): PushDevice
    {
        $token = trim($token);

        return DB::transaction(function () use ($user, $token, $platform): PushDevice {
            $device = PushDevice::query()->updateOrCreate(
                ['token_hash' => PushDevice::hash($token)],
                [
                    'user_id' => $user->getKey(),
                    'token' => $token,
                    'platform' => $platform,
                    // Set on registration so a device that registers and is never sent to still
                    // looks fresh to the pruning sweep for its first window.
                    'last_used_at' => now(),
                ],
            );

            // Ids and the platform only. A token is a bearer credential for sending to the device
            // (CLAUDE.md: never log secrets).
            Log::channel('notifications')->info('Push device registered.', [
                'user_id' => $user->getKey(),
                'platform' => $platform->value,
                'new' => $device->wasRecentlyCreated,
            ]);

            return $device;
        });
    }

    /**
     * Unregisters a device — signing out on a phone, or revoking notifications from its settings.
     * Matching on the hash alone would let one member delete another's row, so the owner is part of
     * the condition.
     */
    public function forget(User $user, string $token): bool
    {
        $deleted = PushDevice::query()
            ->where('user_id', $user->getKey())
            ->where('token_hash', PushDevice::hash(trim($token)))
            ->delete();

        return $deleted > 0;
    }
}
