<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Transport;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * The ad-hoc laravel/ai agent the {@see LaravelAiTransport} drives for a single prompt. Our registry
 * owns the prompt text and generation limits, so this agent is a thin carrier: it exposes the system
 * instructions, the max-tokens/temperature the SDK reads off agent methods, and (when the prompt is
 * structured) the JSON schema converted to Laravel's schema types. An empty schema array means a
 * free-text prompt, which the SDK treats as non-structured.
 *
 * It mirrors {@see AnonymousAgent} by composing the {@see Promptable} trait rather than
 * extending it, so its constructor can carry our prompt data without clashing with the SDK's.
 */
final class RegisteredPromptAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * @param  array<string, mixed>|null  $jsonSchema
     */
    public function __construct(
        private readonly string $systemInstructions,
        private readonly ?array $jsonSchema,
        private readonly int $maxTokensValue,
        private readonly float $temperatureValue,
    ) {}

    public function instructions(): string
    {
        return $this->systemInstructions;
    }

    /**
     * @return iterable<int, mixed>
     */
    public function messages(): iterable
    {
        return [];
    }

    /**
     * @return iterable<int, mixed>
     */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->jsonSchema !== null
            ? SchemaConverter::properties($this->jsonSchema)
            : [];
    }

    public function maxTokens(): int
    {
        return $this->maxTokensValue;
    }

    public function temperature(): float
    {
        return $this->temperatureValue;
    }
}
