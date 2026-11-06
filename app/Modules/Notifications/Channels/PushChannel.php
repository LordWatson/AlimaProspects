<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Models\User;
use App\Modules\Notifications\Data\PushMessage;
use App\Modules\Notifications\Services\PushDelivery;
use Illuminate\Notifications\Notification as BaseNotification;

/**
 * Delivers a notification to a member's registered devices (Phase 12).
 *
 * The channel is now the **adapter** and {@see PushDelivery} the delivery: this asks the
 * notification for its push message and hands it over, and everything that was ever hard here —
 * sending to every device, and deleting the tokens the provider permanently rejected — lives in the
 * service.
 *
 * The split happened in S15, when the signal digest needed a push. The digest is a Mailable with
 * sections rather than a notification with one line, so it cannot come through this channel at all,
 * and the choice was a shared service or a second copy of the token pruning. A second copy is the
 * one that stops pruning the day somebody changes only one of them.
 *
 * It still never throws, for the reason the service documents: push is the least important channel,
 * and a provider outage must not fail the queued notification and drag the other channels into a
 * retry with it.
 */
final class PushChannel
{
    public function __construct(private readonly PushDelivery $delivery) {}

    public function send(object $notifiable, BaseNotification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toPush')) {
            return;
        }

        /** @var PushMessage $message */
        $message = $notification->toPush($notifiable);

        $this->delivery->to($notifiable, $message);
    }
}
