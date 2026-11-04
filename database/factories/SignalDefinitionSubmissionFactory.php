<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Signals\Enums\SignalSubmissionStatus;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalDefinitionSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalDefinitionSubmission>
 */
class SignalDefinitionSubmissionFactory extends Factory
{
    protected $model = SignalDefinitionSubmission::class;

    /**
     * A submission **waiting for an operator**, which is the only state the review queue renders
     * and the state every answer starts from.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'submitted_by' => User::factory(),
            'signal_definition_id' => SignalDefinition::factory(),
            'status' => SignalSubmissionStatus::Pending,
            'note' => 'This one has found us three deals. Worth sharing.',
            'review_note' => null,
            'preset_id' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }

    public function forDefinition(SignalDefinition $definition): static
    {
        return $this->state(fn (array $attributes): array => [
            'team_id' => $definition->team_id,
            'signal_definition_id' => $definition->getKey(),
        ]);
    }

    public function promoted(?SignalDefinition $preset = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SignalSubmissionStatus::Promoted,
            'preset_id' => $preset?->getKey(),
            'review_note' => 'Added to the library.',
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SignalSubmissionStatus::Rejected,
            'review_note' => 'Too close to one we already have.',
            'reviewed_at' => now(),
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SignalSubmissionStatus::Withdrawn,
            'reviewed_at' => now(),
        ]);
    }
}
