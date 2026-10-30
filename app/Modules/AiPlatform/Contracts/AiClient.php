<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Contracts;

use App\Modules\AiPlatform\Data\StructuredAiResponse;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\AiPlatform\Exceptions\UnknownPromptException;
use App\Modules\AiPlatform\Support\UntrustedContent;

/**
 * The single entry point every AI-using feature calls. Given a registered prompt key and an input
 * map, it resolves the versioned template, renders it (wrapping untrusted content safely), enforces
 * the team's credit allowance, calls the model, validates the response against the prompt's JSON
 * schema (retrying on failure), logs token usage + cost per team, and caches identical calls.
 *
 * Callers depend only on this interface — never on the underlying SDK (ADR 0005).
 */
interface AiClient
{
    /**
     * Run the named prompt and return its schema-valid structured output.
     *
     * @param  string  $key  The prompt key, `<module>.<purpose>` (see docs/ai-prompts.md).
     * @param  array<string, mixed>  $input  Template variables. Wrap externally-sourced values in {@see UntrustedContent}.
     * @param  int|null  $version  A specific prompt version, or null for the active version.
     *
     * @throws UnknownPromptException
     * @throws AiCreditExceededException
     * @throws InvalidAiResponseException
     */
    public function structured(string $key, array $input = [], ?int $version = null): StructuredAiResponse;
}
