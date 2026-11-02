<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Signals\Enums\SignalWatchCadence;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalWatch>
 *
 * The watched types are **not** set here. Since S7 they live in the `signal_watch_definition` pivot
 * (ADR 0018 §5), and a factory cannot attach a relation while building attributes — so a test says
 * what a watch looks for with {@see self::watching()}, which is also the only way to make it
 * explicit that a watch with no types finds nothing.
 */
class SignalWatchFactory extends Factory
{
    protected $model = SignalWatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'icp_id' => null,
            'name' => 'Dubai wealth events',
            'market' => Market::Uae,
            'location' => 'Dubai',
            'cadence' => SignalWatchCadence::Daily,
            'is_active' => true,
            'last_run_at' => null,
        ];
    }

    /**
     * Attach the signal types this watch watches for, creating the pivot rows the run reads.
     *
     * @param  list<SignalDefinition>|Collection<int, SignalDefinition>  $definitions
     */
    public function watching(iterable $definitions): static
    {
        return $this->afterCreating(function (SignalWatch $watch) use ($definitions): void {
            $rows = [];

            foreach ($definitions as $definition) {
                $rows[$definition->getKey()] = ['team_id' => $watch->team_id];
            }

            $watch->definitions()->sync($rows);
        });
    }

    /**
     * The shorthand most fixtures want: a watch over the team's own clones of the named presets,
     * created if the team does not have them yet.
     *
     * @param  list<string>  $keys
     */
    public function watchingPresets(array $keys = [SignalPresetCatalogue::FUNDING_ROUND]): static
    {
        return $this->afterCreating(function (SignalWatch $watch) use ($keys): void {
            $rows = [];

            foreach ($keys as $key) {
                $definition = SignalDefinition::query()
                    ->withoutGlobalScope('team')
                    ->where('team_id', $watch->team_id)
                    ->where('key', $key)
                    ->first()
                    ?? SignalDefinition::factory()
                        ->fromCatalogue($key)
                        ->create(['team_id' => $watch->team_id]);

                $rows[$definition->getKey()] = ['team_id' => $watch->team_id];
            }

            $watch->definitions()->sync($rows);
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function weekly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cadence' => SignalWatchCadence::Weekly,
        ]);
    }
}
