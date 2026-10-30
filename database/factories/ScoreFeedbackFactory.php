<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Scoring\Enums\FeedbackRating;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Scoring\Models\ScoreFeedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScoreFeedback>
 */
class ScoreFeedbackFactory extends Factory
{
    protected $model = ScoreFeedback::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'lead_score_id' => LeadScore::factory(),
            'user_id' => User::factory(),
            'rating' => FeedbackRating::Good,
            'comment' => null,
            'scored_value' => 82,
            'scored_grade' => ScoreGrade::A,
        ];
    }

    public function bad(?string $comment = null): self
    {
        return $this->state(fn (): array => [
            'rating' => FeedbackRating::Bad,
            'comment' => $comment,
        ]);
    }
}
