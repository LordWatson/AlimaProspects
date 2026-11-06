<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalDefinitionSpend;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalDefinitionSpend>
 */
class SignalDefinitionSpendFactory extends Factory
{
    protected $model = SignalDefinitionSpend::class;

    /**
     * One ordinary day's searching: a handful of queries, a page of results, a small bill.
     *
     * `signal_definition_id` is **null** by default and `event_key` carries the type, which is the
     * shape a row has once the type has been deleted (ADR 0018 §4) — the harder case, and so the
     * better default. {@see self::forDefinition()} asks for the live attribution.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
            'signal_definition_id' => null,
            'day' => now()->utc()->toDateString(),
            'queries' => 8,
            'results' => 120,
            'search_cost_micros' => 8_000,
            'ai_cost_micros' => 4_000,
        ];
    }

    public function forDefinition(SignalDefinition $definition): static
    {
        return $this->state(fn (array $attributes): array => [
            'team_id' => $definition->team_id,
            'event_key' => $definition->key,
            'signal_definition_id' => $definition->getKey(),
        ]);
    }

    public function on(string $day): static
    {
        return $this->state(fn (array $attributes): array => ['day' => $day]);
    }

    /** A day that cost a given amount in total, split the way a real run splits it. */
    public function costing(int $searchMicros, int $aiMicros = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'search_cost_micros' => $searchMicros,
            'ai_cost_micros' => $aiMicros,
        ]);
    }
}
