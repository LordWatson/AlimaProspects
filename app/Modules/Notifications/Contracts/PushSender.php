<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Data\PushMessage;
use App\Modules\Notifications\Data\PushResult;

/**
 * Sends a push notification to a set of device tokens (Phase 12).
 *
 * The seam exists for the same reason `NewsSearchClient` and `AiTransport` do: tests never touch a
 * provider (CLAUDE.md), and FCM is one plausible provider rather than the only one — the mobile app
 * may ship against APNs directly, or a later deployment against Web Push.
 *
 * The return matters as much as the send. FCM answers **per token**, and the useful part of that
 * answer is which tokens it will never accept again: a device that has been wiped or whose app was
 * uninstalled returns `UNREGISTERED`, and a token we keep sending to after that is a notification
 * that silently goes nowhere for ever. {@see PushResult::$invalidTokens} is how the caller knows
 * to delete it.
 */
interface PushSender
{
    /**
     * @param  list<string>  $tokens  Provider registration tokens. May be empty, in which case
     *                                nothing is sent and an empty result comes back — the caller
     *                                should not have to check first.
     */
    public function send(array $tokens, PushMessage $message): PushResult;
}
