<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Transport;

use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Data\AiPromptRequest;
use App\Modules\AiPlatform\Data\AiTransportResult;
use App\Modules\AiPlatform\Exceptions\AiTransportException;
use Throwable;

/**
 * The default transport: wraps Laravel's first-party AI SDK (`laravel/ai`, ADR 0005). It is the only
 * class in the module that references the SDK — everything else depends on the {@see AiTransport}
 * contract, so swapping the SDK (e.g. to Prism) touches this file alone. Any SDK failure is
 * normalised to an {@see AiTransportException} so callers see one error type.
 */
final class LaravelAiTransport implements AiTransport
{
    public function generate(AiPromptRequest $request): AiTransportResult
    {
        $agent = new RegisteredPromptAgent(
            systemInstructions: $request->system,
            jsonSchema: $request->jsonSchema,
            maxTokensValue: $request->maxTokens,
            temperatureValue: $request->temperature,
        );

        try {
            $response = $agent->prompt(
                $request->prompt,
                provider: $request->provider,
                model: $request->model,
                timeout: $request->timeout,
            );
        } catch (Throwable $e) {
            throw AiTransportException::from($e);
        }

        // When a schema was requested the SDK returns a StructuredAgentResponse; read its decoded
        // payload. Otherwise it is a plain text response, so there is nothing structured to extract.
        $structured = $request->jsonSchema !== null ? $response->toArray() : null;

        return new AiTransportResult(
            structured: $structured,
            text: $response->text,
            inputTokens: $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
            model: $response->meta->model ?? $request->model,
            cacheReadInputTokens: $response->usage->cacheReadInputTokens,
        );
    }
}
