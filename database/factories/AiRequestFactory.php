<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\AiPlatform\Enums\AiRequestStatus;
use App\Modules\AiPlatform\Enums\AiTier;
use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRequest>
 */
class AiRequestFactory extends Factory
{
    protected $model = AiRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $input = fake()->numberBetween(500, 3000);
        $output = fake()->numberBetween(100, 800);

        return [
            'team_id' => Team::factory(),
            'user_id' => null,
            'prompt_key' => 'scoring.qualitative_fit',
            'prompt_version' => 1,
            'tier' => AiTier::Reasoning,
            'model' => 'claude-sonnet-5-5',
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cache_read_input_tokens' => null,
            'cost_micros' => $input * 3 + $output * 15,
            'currency' => 'usd',
            'latency_ms' => fake()->numberBetween(200, 4000),
            'cache_hit' => false,
            'status' => AiRequestStatus::Success,
            'error_code' => null,
        ];
    }

    public function cacheHit(): static
    {
        return $this->state(fn (): array => [
            'cache_hit' => true,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_micros' => 0,
        ]);
    }

    public function failed(string $errorCode = 'ai.invalid_response'): static
    {
        return $this->state(fn (): array => [
            'status' => AiRequestStatus::Failed,
            'error_code' => $errorCode,
        ]);
    }
}
