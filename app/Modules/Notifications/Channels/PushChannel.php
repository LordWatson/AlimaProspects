<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Models\User;
use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Notifications\Data\PushMessage;
use App\Modules\Notifications\Models\PushDevice;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a notification to a member's registered devices (Phase 12).
 *
 * Two jobs, and the second is the one that keeps push working over time:
 *
 * 1. Send the message to every device the member has registered.
 * 2. **Delete the tokens the provider permanently rejected.** A device whose app was uninstalled
 *    returns `UNREGISTERED` for ever; a row we keep is a notification that silently goes nowhere,
 *    and nobody ever notices because nothing fails. This is where the row goes.
 *
 * It never throws. Push is the least important channel — a member who misses one still has the bell
 * and often the email — so a provider outage must not fail the queued notification and drag the
 * other channels into a retry with it.
 */
final class PushChannel
{
    public function __construct(private readonly PushSender $sender) {}

    public function send(object $notifiable, BaseNotification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toPush')) {
            return;
        }

        $devices = PushDevice::query()->where('user_id', $notifiable->getKey())->get();

        if ($devices->isEmpty()) {
            return;
        }

        /** @var PushMessage $message */
        $message = $notification->toPush($notifiable);

        $result = $this->sender->send(
            array_values($devices->pluck('token')->map(static fn (mixed $token): string => (string) $token)->all()),
            $message,
        );

        if ($result->invalidTokens !== []) {
            $hashes = array_map(static fn (string $token): string => PushDevice::hash($token), $result->invalidTokens);

            PushDevice::query()->whereIn('token_hash', $hashes)->delete();

            Log::channel('notifications')->info('Dropped push devices the provider will not accept again.', [
                'user_id' => $notifiable->getKey(),
                'dropped' => count($hashes),
            ]);
        }

        if ($result->anySent()) {
            // Only the devices that actually took it, so the pruning sweep can tell a quiet device
            // from a dead one.
            $sentHashes = array_diff(
                $devices->pluck('token_hash')->map(static fn (mixed $hash): string => (string) $hash)->all(),
                array_map(static fn (string $token): string => PushDevice::hash($token), $result->invalidTokens),
            );

            PushDevice::query()->whereIn('token_hash', $sentHashes)->update(['last_used_at' => now()]);
        }
    }
}
