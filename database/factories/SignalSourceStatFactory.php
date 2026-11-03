<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalSourceStat;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalSourceStat>
 */
class SignalSourceStatFactory extends Factory
{
    protected $model = SignalSourceStat::class;

    /**
     * A source with **too little history to have a reputation**, which is the state every source
     * starts in and the one `authority()` abstains on. A test that wants an opinion asks for one
     * with {@see self::trusted()} or {@see self::distrusted()}, which makes the threshold part of
     * what the test is saying rather than an accident of the default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'source_key' => 'gulf-business',
            'source_name' => 'Gulf Business',
            'signal_definition_id' => null,
            'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
            'kept_count' => 1,
            'dismissed_count' => 0,
            'converted_count' => 0,
            'not_an_event_count' => 0,
            'last_seen_at' => now(),
        ];
    }

    public function forSource(string $key, ?string $name = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_key' => $key,
            'source_name' => $name ?? $key,
        ]);
    }

    public function forDefinition(SignalDefinition $definition): static
    {
        return $this->state(fn (array $attributes): array => [
            'team_id' => $definition->team_id,
            'signal_definition_id' => $definition->getKey(),
            'event_key' => $definition->key,
        ]);
    }

    /**
     * Enough kept and converted signals to earn a high authority.
     *
     * Note the shape: `kept_count` is **how many reached the feed** and the other three are subsets
     * of it, so a state with more dismissals than keeps is one production cannot produce. A fixture
     * that seeded one would test arithmetic nothing will ever be asked to do — which is exactly how
     * the first version of this hid a defect that made the whole advisor dead code.
     */
    public function trusted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kept_count' => 12,
            'dismissed_count' => 1,
            'converted_count' => 4,
        ]);
    }

    /** Enough dismissals to earn a low one — and the evidence a tuning proposal reads. */
    public function distrusted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kept_count' => 12,
            'dismissed_count' => 11,
            'converted_count' => 0,
            'not_an_event_count' => 9,
        ]);
    }
}
