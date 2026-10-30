<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Testing;

use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Data\AiPromptRequest;
use App\Modules\AiPlatform\Data\AiTransportResult;
use App\Modules\AiPlatform\Exceptions\AiTransportException;
use App\Modules\AiPlatform\Transport\LaravelAiTransport;
use RuntimeException;

/**
 * An in-memory {@see AiTransport} for tests: it returns queued results (FIFO) and records every
 * request, so the whole AI Platform pipeline — registry, rendering, validation, retry, cost logging,
 * caching, the credit guard — runs end-to-end without ever touching a provider. Bind it in place of
 * {@see LaravelAiTransport} in tests. External AI is always faked
 * (CLAUDE.md test rules).
 */
final class FakeAiTransport implements AiTransport
{
    /**
     * Queued outcomes: either an {@see AiTransportResult} or a throwable to raise.
     *
     * @var list<AiTransportResult|\Throwable>
     */
    private array $queue = [];

    /**
     * Every request received, in order.
     *
     * @var list<AiPromptRequest>
     */
    public array $requests = [];

    /**
     * Queue a structured result for the next call.
     *
     * @param  array<string, mixed>  $data
     */
    public function queueStructured(array $data, int $inputTokens = 1_000, int $outputTokens = 300, string $model = 'claude-sonnet-5-5', ?int $cacheReadInputTokens = null): self
    {
        $this->queue[] = new AiTransportResult(
            structured: $data,
            text: (string) json_encode($data),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            model: $model,
            cacheReadInputTokens: $cacheReadInputTokens,
        );

        return $this;
    }

    /**
     * Queue a raw text result (no decoded structured payload) for the next call.
     */
    public function queueText(string $text, int $inputTokens = 1_000, int $outputTokens = 300, string $model = 'claude-sonnet-5-5'): self
    {
        $this->queue[] = new AiTransportResult(
            structured: null,
            text: $text,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            model: $model,
        );

        return $this;
    }

    /**
     * Queue a transport failure for the next call.
     */
    public function queueFailure(?\Throwable $e = null): self
    {
        $this->queue[] = $e ?? AiTransportException::from(new RuntimeException('Faked transport failure.'));

        return $this;
    }

    public function generate(AiPromptRequest $request): AiTransportResult
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new RuntimeException('FakeAiTransport: no response was queued for this call.');
        }

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function callCount(): int
    {
        return count($this->requests);
    }

    public function lastRequest(): ?AiPromptRequest
    {
        return $this->requests[array_key_last($this->requests)] ?? null;
    }
}
