<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Data;

use App\Modules\AiPlatform\Contracts\AiTransport;

/**
 * The fully-resolved input handed to an {@see AiTransport}: a
 * concrete provider + model, the rendered system/user prompts, generation limits, and the JSON
 * schema the response must satisfy. This is the stable seam the transport implements, so the
 * underlying SDK (laravel/ai today) can be swapped without touching the rest of the module.
 */
final readonly class AiPromptRequest
{
    /**
     * @param  array<string, mixed>|null  $jsonSchema  JSON-schema (object) describing the required output, or null for free text.
     */
    public function __construct(
        public string $provider,
        public string $model,
        public string $system,
        public string $prompt,
        public int $maxTokens,
        public float $temperature,
        public ?array $jsonSchema = null,
        public int $timeout = 60,
    ) {}

    public function expectsStructuredOutput(): bool
    {
        return $this->jsonSchema !== null;
    }
}
