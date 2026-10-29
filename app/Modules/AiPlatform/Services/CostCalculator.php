<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Services;

/**
 * Turns token counts into an integer cost in micro-units of the configured currency, using the
 * per-model prices in `config('ai-platform.cost')`. Prices are quoted as micro-units per million
 * tokens (e.g. $3.00/Mtok → 3_000_000), which keeps the arithmetic — and the stored cost — exact
 * integers down to sub-cent precision. Prices are indicative; verify against live provider pricing
 * (mvp.md cost model, ADR 0005 risk).
 */
final class CostCalculator
{
    public function costMicros(string $model, int $inputTokens, int $outputTokens): int
    {
        /** @var array<string, array{input_micros_per_mtok: int, output_micros_per_mtok: int}> $models */
        $models = (array) config('ai-platform.cost.models', []);

        /** @var array{input_micros_per_mtok: int, output_micros_per_mtok: int} $rate */
        $rate = $models[$model] ?? (array) config('ai-platform.cost.fallback', [
            'input_micros_per_mtok' => 0,
            'output_micros_per_mtok' => 0,
        ]);

        $input = (int) round($inputTokens * (int) $rate['input_micros_per_mtok'] / 1_000_000);
        $output = (int) round($outputTokens * (int) $rate['output_micros_per_mtok'] / 1_000_000);

        return $input + $output;
    }

    public function currency(): string
    {
        return (string) config('ai-platform.cost.currency', 'usd');
    }
}
