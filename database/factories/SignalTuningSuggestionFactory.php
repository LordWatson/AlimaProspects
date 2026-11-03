<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Enums\SignalTuningKind;
use App\Modules\Signals\Enums\SignalTuningStatus;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalTuningSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalTuningSuggestion>
 */
class SignalTuningSuggestionFactory extends Factory
{
    protected $model = SignalTuningSuggestion::class;

    /**
     * An open ignore-source proposal, which is the state the panel renders and the one an accept
     * or a decline starts from.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $source = $this->faker->unique()->domainWord();

        return [
            'team_id' => Team::factory(),
            'kind' => SignalTuningKind::IgnoreSource,
            'signal_definition_id' => null,
            'payload' => ['source_key' => $source, 'source_name' => ucfirst($source).' Daily'],
            'evidence' => ['kept' => 1, 'dismissed' => 11, 'observations' => 12, 'rate' => 0.92],
            'status' => SignalTuningStatus::Proposed,
            'fingerprint' => sha1('ignore_source|0|'.$source),
        ];
    }

    /** A threshold nudge on a real definition — the other half of what the advisor proposes. */
    public function raiseConfidence(SignalDefinition $definition, int $from = 60, int $to = 70): static
    {
        return $this->state(fn (array $attributes): array => [
            'team_id' => $definition->team_id,
            'kind' => SignalTuningKind::RaiseConfidence,
            'signal_definition_id' => $definition->getKey(),
            'payload' => ['threshold' => 'min_confidence', 'from' => $from, 'to' => $to],
            'evidence' => [
                'definition_name' => $definition->name,
                'dismissals' => 12,
                'observations' => 15,
                'rate' => 0.8,
            ],
            'fingerprint' => sha1('raise_confidence|'.$definition->getKey().'|min_confidence'),
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SignalTuningStatus::Declined,
            'actioned_at' => now(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SignalTuningStatus::Accepted,
            'actioned_at' => now(),
        ]);
    }
}
