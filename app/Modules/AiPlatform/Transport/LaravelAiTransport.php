<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Transport;

use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Data\AiPromptRequest;
use App\Modules\AiPlatform\Data\AiTransportResult;
use App\Modules\AiPlatform\Exceptions\AiTransportException;
use Laravel\Ai\Providers\Tools\WebSearch;
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
            providerTools: $this->tools($request),
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

        [$sources, $searches] = $request->webSearch === null ? [[], 0] : $this->searchActivity($response->steps);

        return new AiTransportResult(
            structured: $structured,
            text: $response->text,
            inputTokens: $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
            model: $response->meta->model ?? $request->model,
            cacheReadInputTokens: $response->usage->cacheReadInputTokens,
            sources: $sources,
            webSearches: $searches,
        );
    }

    /**
     * @return list<WebSearch>
     */
    private function tools(AiPromptRequest $request): array
    {
        if ($request->webSearch === null) {
            return [];
        }

        $tool = (new WebSearch)->max(max(1, $request->webSearch->maxSearches));

        return [$request->webSearch->country === null ? $tool : $tool->location(country: $request->webSearch->country)];
    }

    /**
     * Every page the provider's search tool returned, and how many searches it ran, read off the
     * raw tool blocks of each step.
     *
     * The blocks rather than the SDK's merged citation list, for two reasons: a citation list
     * cannot say how many searches were *billed*, and a caller that wants to refuse an answer not
     * found on a returned page needs the pages that were returned, cited or not.
     *
     * @param  iterable<mixed>  $steps
     * @return array{list<array{url: string, title: ?string, page_age: ?string}>, int}
     */
    private function searchActivity(iterable $steps): array
    {
        $sources = [];
        $searches = 0;

        foreach ($steps as $step) {
            foreach ($step->providerToolCalls ?? [] as $call) {
                $block = (array) $call->data;

                if ($call->type === 'server_tool_use' && ($block['name'] ?? null) === 'web_search') {
                    $searches++;
                }

                // A failed search carries an error object here, not a list.
                if ($call->type !== 'web_search_tool_result' || ! array_is_list((array) ($block['content'] ?? null))) {
                    continue;
                }

                foreach ((array) $block['content'] as $result) {
                    if (is_array($result) && is_string($result['url'] ?? null) && $result['url'] !== '') {
                        $sources[$result['url']] ??= [
                            'url' => $result['url'],
                            'title' => is_string($result['title'] ?? null) ? $result['title'] : null,
                            'page_age' => is_string($result['page_age'] ?? null) ? $result['page_age'] : null,
                        ];
                    }
                }
            }
        }

        return [array_values($sources), $searches];
    }
}
