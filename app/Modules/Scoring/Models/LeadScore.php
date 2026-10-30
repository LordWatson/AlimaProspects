<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Casts\CriterionScoreList;
use App\Modules\Scoring\Data\CriterionScore;
use App\Modules\Scoring\Enums\ScoreGrade;
use Database\Factories\LeadScoreFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A lead's hybrid ICP-fit score (Phase 9): the blended 0–100 `score` and its A–D `grade`, the two
 * halves that produced it (`deterministic_score` + `ai_score`), the per-criterion `breakdown`, and
 * the plain-English `explanation`. Exactly one row per (lead, ICP) — a re-score updates it in place
 * so {@see ScoreFeedback} on it survives. Tenant scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lead_id
 * @property int $icp_id
 * @property int $score
 * @property ScoreGrade $grade
 * @property int $deterministic_score
 * @property int|null $ai_score
 * @property list<CriterionScore> $breakdown
 * @property string|null $explanation
 * @property list<string>|null $strengths
 * @property list<string>|null $concerns
 * @property bool $excluded
 * @property string|null $excluded_by
 * @property string|null $prompt_key
 * @property int|null $prompt_version
 * @property string|null $model
 * @property int $cost_micros
 * @property Carbon|null $scored_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ScoreFeedback> $feedback
 */
class LeadScore extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<LeadScoreFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'lead_id',
        'icp_id',
        'score',
        'grade',
        'deterministic_score',
        'ai_score',
        'breakdown',
        'explanation',
        'strengths',
        'concerns',
        'excluded',
        'excluded_by',
        'prompt_key',
        'prompt_version',
        'model',
        'cost_micros',
        'scored_at',
    ];

    /**
     * The lead this score belongs to.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The ICP definition this score was measured against.
     *
     * @return BelongsTo<Icp, $this>
     */
    public function icp(): BelongsTo
    {
        return $this->belongsTo(Icp::class);
    }

    /**
     * Members' verdicts on this score.
     *
     * @return HasMany<ScoreFeedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(ScoreFeedback::class);
    }

    /**
     * The lead's score against its team's **active** ICP — the one the screens surface — picked from
     * an already-loaded `scores` relation, so this costs no query. Scores against an ICP that is no
     * longer active stay on the lead as history and are skipped here.
     */
    public static function activeFor(Lead $lead): ?self
    {
        return $lead->scores
            ->first(static fn (self $score): bool => $score->icp?->is_active === true);
    }

    /**
     * Whether the score still reflects its inputs — i.e. it was produced after the last change to
     * the lead's enriched data and to the ICP it was measured against. A stale score is what the
     * re-score triggers refresh; a fresh one is reused rather than re-spending AI credit.
     *
     * Only the two signals that actually feed a score are compared. The lead's `updated_at` is
     * deliberately not one of them: scoring itself advances the lead's stage, which would otherwise
     * leave every score instantly stale and re-spend credit on the next trigger. Edits that are not
     * enrichment (Phase 10 notes, tags, stage moves) don't change the inputs either; anything that
     * should re-grade a lead forces the score.
     */
    public function isFreshFor(Lead $lead, Icp $icp): bool
    {
        if ($this->scored_at === null) {
            return false;
        }

        foreach ([$lead->enriched_at, $icp->updated_at] as $changedAt) {
            if ($changedAt !== null && $changedAt->greaterThan($this->scored_at)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'grade' => ScoreGrade::class,
            'deterministic_score' => 'integer',
            'ai_score' => 'integer',
            'breakdown' => CriterionScoreList::class,
            'strengths' => 'array',
            'concerns' => 'array',
            'excluded' => 'boolean',
            'prompt_version' => 'integer',
            'cost_micros' => 'integer',
            'scored_at' => 'datetime',
        ];
    }

    protected static function newFactory(): LeadScoreFactory
    {
        return LeadScoreFactory::new();
    }
}
