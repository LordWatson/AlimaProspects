<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\AiInsights\Enums\InsightSeverity;
use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\AiInsights\Models\Insight;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Insight>
 */
class InsightFactory extends Factory
{
    protected $model = Insight::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => InsightType::ContactToday,
            'status' => InsightStatus::New,
            'severity' => InsightSeverity::Action,
            // Deterministic by default, so a factory-made insight exercises the "worded on the
            // client" path rather than quietly testing AI prose that most rows never have.
            'headline' => null,
            'body' => null,
            'payload' => [
                'count' => 2,
                'leads' => [
                    ['id' => 1, 'company_name' => 'Acme Ltd', 'grade' => 'A', 'score' => 88, 'restricted' => false],
                    ['id' => 2, 'company_name' => 'Globex Ltd', 'grade' => 'B', 'score' => 71, 'restricted' => false],
                ],
            ],
            'period_start' => now()->subDays(7),
            'period_end' => now(),
            'prompt_key' => null,
            'prompt_version' => null,
            'model' => null,
            'cost_micros' => 0,
            'generated_at' => now(),
            'dedupe_key' => InsightType::ContactToday->value.':'.$this->faker->unique()->numerify('2026-10-##'),
            'dismissed_by' => null,
            'dismissed_at' => null,
        ];
    }

    public function ofType(InsightType $type): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'severity' => $type->defaultSeverity(),
            'dedupe_key' => $type->value.':'.$this->faker->unique()->numerify('2026-10-##'),
        ]);
    }

    /**
     * An AI-written row: prose plus the trace columns a real one carries.
     */
    public function fromAi(string $headline = 'Your reply rate doubled on LinkedIn'): self
    {
        return $this->state(fn (): array => [
            'type' => InsightType::PeriodicDigest,
            'severity' => InsightSeverity::Info,
            'headline' => $headline,
            'body' => 'Six of your eleven LinkedIn approaches came back this week, against one of nine by email.',
            'payload' => ['metrics' => ['replied' => 7, 'contacted' => 20]],
            'prompt_key' => 'insights.periodic_digest',
            'prompt_version' => 1,
            'model' => 'claude-sonnet-5-5',
            'cost_micros' => 4_200,
        ]);
    }

    public function dismissed(): self
    {
        return $this->state(fn (): array => [
            'status' => InsightStatus::Dismissed,
            'dismissed_at' => now(),
        ]);
    }
}
