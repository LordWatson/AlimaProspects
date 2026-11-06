<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalCluster>
 */
class SignalClusterFactory extends Factory
{
    protected $model = SignalCluster::class;

    /**
     * An empty cluster by default: no primary and no sources, which is the state
     * `SignalClusterer::ensure()` creates and the state a reconcile has to be able to fix. A test
     * that wants a populated one builds its signals and reconciles, which is also the only way the
     * counters are ever right.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'cluster_key' => sha1($this->faker->unique()->uuid()),
            'signal_definition_id' => null,
            'event_key' => SignalPresetCatalogue::ACQUISITION,
            'primary_signal_id' => null,
            'sources_count' => 0,
            'first_published_at' => null,
            'last_published_at' => null,
            'subject_fingerprint' => 'acme',
        ];
    }

    /**
     * Pointed at the signal that represents it, with the counters a single-member cluster has.
     */
    public function primary(Signal $signal): static
    {
        return $this->state(fn (array $attributes): array => [
            'team_id' => $signal->team_id,
            'event_key' => $signal->event_key,
            'primary_signal_id' => $signal->getKey(),
            'sources_count' => 1,
            'first_published_at' => $signal->published_at,
            'last_published_at' => $signal->published_at,
        ]);
    }
}
