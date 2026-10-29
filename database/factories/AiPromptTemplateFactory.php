<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\AiPlatform\Enums\AiTier;
use App\Modules\AiPlatform\Models\AiPromptTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPromptTemplate>
 */
class AiPromptTemplateFactory extends Factory
{
    protected $model = AiPromptTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'test.'.fake()->unique()->slug(2),
            'version' => 1,
            'tier' => AiTier::Reasoning,
            'system' => 'You are a careful assistant. Respond only with valid JSON.',
            'body' => 'Summarise the following: {{subject}}',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                ],
                'required' => ['summary'],
            ],
            'max_tokens' => 1024,
            'temperature' => 0.2,
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    public function cheap(): static
    {
        return $this->state(fn (): array => ['tier' => AiTier::Cheap]);
    }

    public function withoutSchema(): static
    {
        return $this->state(fn (): array => ['schema' => null]);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function schema(array $schema): static
    {
        return $this->state(fn (): array => ['schema' => $schema]);
    }

    public function key(string $key, int $version = 1): static
    {
        return $this->state(fn (): array => ['key' => $key, 'version' => $version]);
    }
}
