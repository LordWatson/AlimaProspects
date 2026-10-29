<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Data;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Enums\AiTier;

/**
 * The validated result of an {@see AiClient::structured()} call:
 * schema-valid data plus the trace metadata (which prompt key+version, which model, token usage,
 * cost and whether it was served from cache). Product modules typically hydrate {@see self::$data}
 * into their own spatie/laravel-data DTO.
 */
final readonly class StructuredAiResponse
{
    /**
     * @param  array<string, mixed>  $data  Schema-valid output.
     */
    public function __construct(
        public array $data,
        public string $promptKey,
        public int $promptVersion,
        public AiTier $tier,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $costMicros,
        public string $currency,
        public bool $cacheHit,
        public int $latencyMs,
    ) {}

    /**
     * Read a top-level value from the validated data.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
