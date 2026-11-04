<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Channels\PushChannel;
use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Notifications\Data\PushMessage;
use App\Modules\Notifications\Models\PushDevice;
use Illuminate\Support\Facades\Log;

/**
 * Sends one message to one member's devices, and keeps the device table honest (Phase 12, extracted
 * in S15).
 *
 * This was {@see PushChannel}'s whole body until the signal digest needed it. The digest is a
 * **Mailable with sections** rather than a notification with one line — which is why it has never
 * gone through the notification pipeline — so giving it a push meant either a second copy of the
 * token handling or this. A second copy is the worse answer, because the part worth getting right
 * is not the send:
 *
 * **It deletes the tokens the provider permanently rejected.** A device whose app was uninstalled
 * returns `UNREGISTERED` for ever, and a row we keep is a notification that silently goes nowhere
 * with nothing ever failing. A duplicate of that logic is a duplicate that stops pruning the day
 * somebody changes one of them.
 *
 * **It never throws.** Push is the least important channel — a member who misses one still has the
 * bell and usually the email — so a provider outage must not fail the caller and drag the other
 * channels into a retry with it.
 */
final class PushDelivery
{
    public function __construct(private readonly PushSender $sender) {}

    /**
     * Deliver to every device this member has registered. Returns how many took it.
     *
     * Zero is an ordinary answer and not a failure: a member with no registered device, or one
     * whose only device has been wiped, is somebody who simply does not get a push.
     */
    public function to(User $user, PushMessage $message): int
    {
        $devices = PushDevice::query()->where('user_id', $user->getKey())->get();

        if ($devices->isEmpty()) {
            return 0;
        }

        $result = $this->sender->send(
            array_values($devices->pluck('token')->map(static fn (mixed $token): string => (string) $token)->all()),
            $message,
        );

        if ($result->invalidTokens !== []) {
            $hashes = array_map(static fn (string $token): string => PushDevice::hash($token), $result->invalidTokens);

            PushDevice::query()->whereIn('token_hash', $hashes)->delete();

            Log::channel('notifications')->info('Dropped push devices the provider will not accept again.', [
                'user_id' => $user->getKey(),
                'dropped' => count($hashes),
            ]);
        }

        if (! $result->anySent()) {
            return 0;
        }

        // Only the devices that actually took it, so the pruning sweep can tell a quiet device
        // from a dead one.
        $sentHashes = array_diff(
            $devices->pluck('token_hash')->map(static fn (mixed $hash): string => (string) $hash)->all(),
            array_map(static fn (string $token): string => PushDevice::hash($token), $result->invalidTokens),
        );

        PushDevice::query()->whereIn('token_hash', $sentHashes)->update(['last_used_at' => now()]);

        return count($sentHashes);
    }
}
