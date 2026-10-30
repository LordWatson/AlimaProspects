<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Testing;

use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Notifications\Data\PushMessage;
use App\Modules\Notifications\Data\PushResult;

/**
 * An in-memory {@see PushSender} for tests. Records every send and can be told to reject a token
 * the way FCM rejects an uninstalled app, so the "a dead device is deleted" path is testable
 * without a provider. External providers are always faked (CLAUDE.md test rules).
 */
final class FakePushSender implements PushSender
{
    /**
     * Every send, in order.
     *
     * @var list<array{tokens: list<string>, message: PushMessage}>
     */
    public array $sends = [];

    /**
     * Tokens to report as permanently rejected.
     *
     * @var list<string>
     */
    private array $rejecting = [];

    /**
     * @param  list<string>  $tokens
     */
    public function reject(array $tokens): self
    {
        $this->rejecting = $tokens;

        return $this;
    }

    public function send(array $tokens, PushMessage $message): PushResult
    {
        $this->sends[] = ['tokens' => $tokens, 'message' => $message];

        $invalid = array_values(array_intersect($tokens, $this->rejecting));
        $sent = count($tokens) - count($invalid);

        return new PushResult(sent: $sent, failed: count($invalid), invalidTokens: $invalid);
    }

    public function sendCount(): int
    {
        return count($this->sends);
    }

    /**
     * Every token ever sent to, flattened — the usual assertion.
     *
     * @return list<string>
     */
    public function tokens(): array
    {
        $tokens = [];

        foreach ($this->sends as $send) {
            foreach ($send['tokens'] as $token) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    public function lastMessage(): ?PushMessage
    {
        $last = $this->sends[array_key_last($this->sends)] ?? null;

        return $last === null ? null : $last['message'];
    }
}
