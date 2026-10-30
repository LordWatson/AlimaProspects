<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Data;

/**
 * A raw result from the transport: the decoded structured payload (when a schema was requested),
 * the plain text, token usage and the concrete model that answered. Cost and schema validation are
 * the client's concern, not the transport's.
 */
final readonly class AiTransportResult
{
    /**
     * @param  array<string, mixed>|null  $structured  The decoded structured output, or null for a free-text response.
     */
    public function __construct(
        public ?array $structured,
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        public string $model,
        public ?int $cacheReadInputTokens = null,
    ) {}
}
