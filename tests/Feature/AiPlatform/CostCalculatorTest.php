<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Services\CostCalculator;

beforeEach(function (): void {
    $this->calculator = new CostCalculator;
});

it('prices the reasoning model (Sonnet) from the configured per-Mtok rates', function (): void {
    // 1500 * 3 + 500 * 15 = 4500 + 7500 = 12_000 micro-units ($0.012).
    expect($this->calculator->costMicros('claude-sonnet-5-5', 1_500, 500))->toBe(12_000);
});

it('prices the cheap model (Haiku) from its own rates', function (): void {
    // 2000 * 1 + 500 * 5 = 2000 + 2500 = 4_500 micro-units.
    expect($this->calculator->costMicros('claude-haiku-5-5', 2_000, 500))->toBe(4_500);
});

it('falls back to the default rate for an unknown model', function (): void {
    // Fallback matches the reasoning rate: 1000 * 3 + 0 = 3_000.
    expect($this->calculator->costMicros('some-future-model', 1_000, 0))->toBe(3_000);
});

it('reports the configured currency', function (): void {
    expect($this->calculator->currency())->toBe('usd');
});
