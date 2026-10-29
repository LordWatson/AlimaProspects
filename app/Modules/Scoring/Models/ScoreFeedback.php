<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Scoring\Enums\FeedbackRating;
use App\Modules\Scoring\Enums\ScoreGrade;
use Database\Factories\ScoreFeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One member's verdict on a {@see LeadScore} — "looks right" / "looks wrong" with an optional note
 * — plus a snapshot of the score and grade they were reacting to (a re-score updates the score row
 * in place). At most one current verdict per member per score. Tenant scoped via
 * {@see BelongsToTeam}.
 *
 * The MVP captures the signal only; using it to tune weights and prompts is post-MVP.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lead_score_id
 * @property int|null $user_id
 * @property FeedbackRating $rating
 * @property string|null $comment
 * @property int $scored_value
 * @property ScoreGrade $scored_grade
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ScoreFeedback extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<ScoreFeedbackFactory> */
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'score_feedback';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'lead_score_id',
        'user_id',
        'rating',
        'comment',
        'scored_value',
        'scored_grade',
    ];

    /**
     * The score this verdict is about.
     *
     * @return BelongsTo<LeadScore, $this>
     */
    public function leadScore(): BelongsTo
    {
        return $this->belongsTo(LeadScore::class);
    }

    /**
     * The member who gave it, or null once they've been deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'rating' => FeedbackRating::class,
            'scored_value' => 'integer',
            'scored_grade' => ScoreGrade::class,
        ];
    }

    protected static function newFactory(): ScoreFeedbackFactory
    {
        return ScoreFeedbackFactory::new();
    }
}
