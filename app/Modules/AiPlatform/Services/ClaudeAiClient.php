<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Services;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Data\AiPromptRequest;
use App\Modules\AiPlatform\Data\AiTransportResult;
use App\Modules\AiPlatform\Data\StructuredAiResponse;
use App\Modules\AiPlatform\Enums\AiRequestStatus;
use App\Modules\AiPlatform\Exceptions\AiPlatformException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\AiPlatform\Models\AiPromptTemplate;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * The default {@see AiClient}: the one orchestration point wiring the registry, injection-safe
 * rendering, the credit guard, the transport, schema validation + retry, cost/usage logging and the
 * response cache together. It depends on the {@see AiTransport} seam, never the SDK directly, so the
 * provider can be swapped and tests can fake the boundary (ADR 0005).
 */
final class ClaudeAiClient implements AiClient
{
    public function __construct(
        private readonly AiTransport $transport,
        private readonly PromptRegistry $registry,
        private readonly PromptInterpolator $interpolator,
        private readonly JsonSchemaValidator $validator,
        private readonly CostCalculator $cost,
        private readonly AiUsageLogger $usage,
        private readonly AiCreditGuard $creditGuard,
        private readonly TeamContext $teamContext,
        private readonly CacheRepository $cache,
    ) {}

    public function structured(string $key, array $input = [], ?int $version = null): StructuredAiResponse
    {
        $template = $this->registry->resolve($key, $version);
        $team = $this->currentTeam();

        // Margin protection: refuse before spending anything when the team is over its allowance.
        $this->creditGuard->ensureWithinAllowance($team);

        [$provider, $model] = $this->resolveProviderAndModel($template);

        $cacheKey = $this->cacheKey($template, $provider, $model, $input);

        if ($this->cacheEnabled() && ($cached = $this->cache->get($cacheKey)) !== null) {
            /** @var array<string, mixed> $cached */
            return $this->fromCache($template, $model, $cached);
        }

        $request = new AiPromptRequest(
            provider: $provider,
            model: $model,
            system: $this->interpolator->render((string) $template->system, $input),
            prompt: $this->interpolator->render($template->body, $input),
            maxTokens: $template->max_tokens ?? (int) config('ai-platform.defaults.max_tokens', 2048),
            temperature: $template->temperature ?? (float) config('ai-platform.defaults.temperature', 0.2),
            jsonSchema: $template->schema,
            timeout: (int) config('ai-platform.defaults.timeout', 60),
        );

        return $this->generateWithValidation($template, $request, $input, $cacheKey);
    }

    /**
     * Run the transport with schema validation, retrying on an invalid response.
     *
     * @param  array<string, mixed>  $input
     */
    private function generateWithValidation(
        AiPromptTemplate $template,
        AiPromptRequest $request,
        array $input,
        string $cacheKey,
    ): StructuredAiResponse {
        $attempts = max(1, (int) config('ai-platform.retries.schema', 1) + 1);
        $errors = [];
        $result = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $startedAt = hrtime(true);

            try {
                $result = $this->transport->generate($request);
            } catch (AiPlatformException $e) {
                $this->usage->record(
                    $template, $request->model, $template->tier,
                    inputTokens: 0, outputTokens: 0, cacheReadInputTokens: null,
                    costMicros: 0, currency: $this->cost->currency(), latencyMs: $this->elapsedMs($startedAt),
                    cacheHit: false, status: AiRequestStatus::Failed, errorCode: $e->errorCode(),
                );

                throw $e;
            }

            $latencyMs = $this->elapsedMs($startedAt);
            $data = $this->extractData($result);
            $errors = $template->hasSchema() ? $this->validator->validate($data, (array) $template->schema) : [];

            if ($errors === []) {
                return $this->onSuccess($template, $result, $data, $cacheKey, $latencyMs);
            }

            // Every invalid attempt is still a real provider call that spent tokens — log it so the
            // ledger (and the credit guard) count every retry, not just the final outcome.
            $this->recordResult($template, $result, $latencyMs, AiRequestStatus::Failed, 'ai.invalid_response');
        }

        // Exhausted all attempts without a schema-valid response; never return unvalidated data.
        throw InvalidAiResponseException::afterRetries($template->key, $attempts, $errors);
    }

    /**
     * Log one provider call to the usage ledger and return its computed cost (micro-units).
     */
    private function recordResult(
        AiPromptTemplate $template,
        AiTransportResult $result,
        int $latencyMs,
        AiRequestStatus $status,
        ?string $errorCode = null,
    ): int {
        $costMicros = $this->cost->costMicros($result->model, $result->inputTokens, $result->outputTokens);

        $this->usage->record(
            $template, $result->model, $template->tier,
            inputTokens: $result->inputTokens, outputTokens: $result->outputTokens,
            cacheReadInputTokens: $result->cacheReadInputTokens, costMicros: $costMicros,
            currency: $this->cost->currency(), latencyMs: $latencyMs,
            cacheHit: false, status: $status, errorCode: $errorCode,
        );

        return $costMicros;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function onSuccess(
        AiPromptTemplate $template,
        AiTransportResult $result,
        array $data,
        string $cacheKey,
        int $latencyMs,
    ): StructuredAiResponse {
        $costMicros = $this->recordResult($template, $result, $latencyMs, AiRequestStatus::Success);

        if ($this->cacheEnabled()) {
            $this->cache->put($cacheKey, [
                'data' => $data,
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'cost_micros' => $costMicros,
            ], (int) config('ai-platform.cache.ttl', 3600));
        }

        return new StructuredAiResponse(
            data: $data,
            promptKey: $template->key,
            promptVersion: $template->version,
            tier: $template->tier,
            model: $result->model,
            inputTokens: $result->inputTokens,
            outputTokens: $result->outputTokens,
            costMicros: $costMicros,
            currency: $this->cost->currency(),
            cacheHit: false,
            latencyMs: $latencyMs,
        );
    }

    /**
     * Build the response for a cache hit — no provider call, zero cost, still logged.
     *
     * @param  array<string, mixed>  $cached
     */
    private function fromCache(AiPromptTemplate $template, string $model, array $cached): StructuredAiResponse
    {
        /** @var array<string, mixed> $data */
        $data = $cached['data'] ?? [];

        $this->usage->record(
            $template, $model, $template->tier,
            inputTokens: 0, outputTokens: 0, cacheReadInputTokens: null,
            costMicros: 0, currency: $this->cost->currency(), latencyMs: 0,
            cacheHit: true, status: AiRequestStatus::Success,
        );

        return new StructuredAiResponse(
            data: $data,
            promptKey: $template->key,
            promptVersion: $template->version,
            tier: $template->tier,
            model: $model,
            inputTokens: 0,
            outputTokens: 0,
            costMicros: 0,
            currency: $this->cost->currency(),
            cacheHit: true,
            latencyMs: 0,
        );
    }

    /**
     * Pull the structured payload from a transport result, decoding the text as JSON if the
     * provider did not return parsed structured output.
     *
     * @return array<string, mixed>
     */
    private function extractData(AiTransportResult $result): array
    {
        if (is_array($result->structured)) {
            return $result->structured;
        }

        $decoded = json_decode($result->text, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveProviderAndModel(AiPromptTemplate $template): array
    {
        $tier = (array) config('ai-platform.tiers.'.$template->tier->configKey(), []);

        return [
            (string) ($tier['provider'] ?? config('ai.default', 'anthropic')),
            (string) ($tier['model'] ?? ''),
        ];
    }

    /**
     * A stable cache key over the full call identity: the team (never share a cached result — or its
     * skipped cost — across tenants), the prompt key+version, the provider+model, a hash of the
     * template content (so an in-place edit can't serve stale output), and the normalised input.
     * Trusted and untrusted values hash differently, since they render differently (verbatim vs. a
     * delimited data block) — a cached result from one must never be served for the other.
     *
     * @param  array<string, mixed>  $input
     */
    private function cacheKey(AiPromptTemplate $template, string $provider, string $model, array $input): string
    {
        $team = $this->teamContext->id() ?? 'system';
        $templateHash = substr(hash('sha256', (string) json_encode([$template->system, $template->body, $template->schema])), 0, 12);
        $inputHash = hash('sha256', (string) json_encode($this->normaliseForHash($input)));

        return "ai:{$team}:{$template->key}:{$template->version}:{$templateHash}:{$provider}:{$model}:{$inputHash}";
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    private function normaliseForHash(array $input): array
    {
        $out = [];

        foreach ($input as $key => $value) {
            $out[$key] = match (true) {
                // Tag untrusted values so they can never collide with the same trusted string.
                $value instanceof UntrustedContent => ['__untrusted' => $value->value],
                is_array($value) => $this->normaliseForHash($value),
                default => $value,
            };
        }

        ksort($out);

        return $out;
    }

    private function currentTeam(): ?Team
    {
        $id = $this->teamContext->id();

        return $id !== null ? Team::query()->find($id) : null;
    }

    private function cacheEnabled(): bool
    {
        return (bool) config('ai-platform.cache.enabled', true);
    }

    private function elapsedMs(int $startedAtHrtime): int
    {
        return (int) ((hrtime(true) - $startedAtHrtime) / 1_000_000);
    }
}
