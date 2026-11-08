<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\LeadSourcing\Enums\EvidenceVerdict;
use App\Modules\Scoring\Models\ScoreFeedback;
use Database\Factories\LeadEvidenceFeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One member's verdict on one item of {@see LeadEvidence} (PL1) — the reason-level sibling of
 * {@see ScoreFeedback}, deliberately the same shape so a team's "this is wrong" is read from one
 * place whether it was said about a score or about a reason.
 *
 * One row per member per item: changing your mind replaces your verdict rather than voting twice.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lead_evidence_id
 * @property int|null $user_id null once the member has left; the verdict stays
 * @property EvidenceVerdict $verdict
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeadEvidenceFeedback extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<LeadEvidenceFeedbackFactory> */
    use HasFactory;

    protected $table = 'lead_evidence_feedback';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'lead_evidence_id',
        'user_id',
        'verdict',
        'note',
    ];

    /**
     * @return BelongsTo<LeadEvidence, $this>
     */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(LeadEvidence::class, 'lead_evidence_id');
    }

    /**
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
            'verdict' => EvidenceVerdict::class,
        ];
    }

    protected static function newFactory(): LeadEvidenceFeedbackFactory
    {
        return LeadEvidenceFeedbackFactory::new();
    }
}
