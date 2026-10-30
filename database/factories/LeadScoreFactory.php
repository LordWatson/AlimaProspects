<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Data\CriterionScore;
use App\Modules\Scoring\Enums\CriterionMatch;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadScore>
 */
class LeadScoreFactory extends Factory
{
    protected $model = LeadScore::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $score = fake()->numberBetween(0, 100);

        return [
            'team_id' => Team::factory(),
            'lead_id' => Lead::factory(),
            'icp_id' => Icp::factory(),
            'score' => $score,
            'grade' => ScoreGrade::fromScore($score),
            'deterministic_score' => $score,
            'ai_score' => $score,
            'breakdown' => [
                new CriterionScore(
                    type: CriterionType::Industry,
                    value: 'Coffee roasting',
                    weight: 80,
                    match: CriterionMatch::Matched,
                    contribution: 100,
                    evidence: 'Coffee roasting',
                ),
            ],
            'explanation' => 'Matches the profile on industry and location.',
            'strengths' => ['Right industry'],
            'concerns' => [],
            'excluded' => false,
            'excluded_by' => null,
            'prompt_key' => 'scoring.qualitative_fit',
            'prompt_version' => 1,
            'model' => 'claude-sonnet-5-5',
            'cost_micros' => 1_200,
            'scored_at' => now(),
        ];
    }

    /**
     * A score the rules produced alone (the AI half was unavailable).
     */
    public function rulesOnly(): self
    {
        return $this->state(fn (): array => [
            'ai_score' => null,
            'explanation' => null,
            'strengths' => null,
            'concerns' => null,
            'prompt_key' => null,
            'prompt_version' => null,
            'model' => null,
            'cost_micros' => 0,
        ]);
    }

    /**
     * A lead the ICP rules out outright.
     */
    public function excluded(string $by = 'acme.test'): self
    {
        return $this->state(fn (): array => [
            'score' => 0,
            'grade' => ScoreGrade::D,
            'deterministic_score' => 0,
            'ai_score' => null,
            'excluded' => true,
            'excluded_by' => $by,
            'explanation' => null,
        ]);
    }

    public function grade(ScoreGrade $grade, int $score): self
    {
        return $this->state(fn (): array => ['grade' => $grade, 'score' => $score]);
    }
}
