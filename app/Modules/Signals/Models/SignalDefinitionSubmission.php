<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Signals\Enums\SignalSubmissionStatus;
use Database\Factories\SignalDefinitionSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A team offering one of their signal types to the platform library (S15 task 4).
 *
 * The row exists because of plan decision #3: **an admin curates, a team can only submit.** A team
 * publishing straight to the library would put one tenant's taste — or mistake, or advertisement —
 * into everybody else's library with nobody having reviewed it. So this is a request, and promoting
 * it is an operator's act with a note attached.
 *
 * Tenant-scoped via {@see BelongsToTeam}, which is what makes the team's own "what happened to my
 * submission?" read safe. **The review queue is the exception** and reads it cross-tenant through
 * `Admin\Support\PlatformContext`, like every other platform-wide read in the panel — and it
 * deliberately shows the type's performance without showing whose it is, because an operator
 * choosing between two submissions should be choosing on the evidence.
 *
 * `signal_definition_id` is `cascadeOnDelete`, unlike almost every other foreign key in this
 * module: a submission is a request *about a row*, and with the row gone there is nothing to
 * promote. `preset_id` is `nullOnDelete` for the opposite reason — withdrawing a preset from the
 * library must not erase the record of where it came from.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $submitted_by
 * @property int $signal_definition_id
 * @property SignalSubmissionStatus $status
 * @property string|null $note what the team said when they offered it
 * @property string|null $review_note what the operator said when they answered
 * @property int|null $preset_id the preset a promotion produced
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalDefinitionSubmission extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalDefinitionSubmissionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'submitted_by',
        'signal_definition_id',
        'status',
        'note',
        'review_note',
        'preset_id',
        'reviewed_by',
        'reviewed_at',
    ];

    /**
     * The type being offered.
     *
     * `withTrashed` although the key cascades: a soft-deleted definition does not cascade, so a team
     * that removed the type while the submission waited leaves a row whose relation would otherwise
     * read as null and render as a blank queue entry.
     *
     * @return BelongsTo<SignalDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(SignalDefinition::class, 'signal_definition_id')->withTrashed();
    }

    /**
     * The preset it became, if it was promoted.
     *
     * @return BelongsTo<SignalDefinition, $this>
     */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(SignalDefinition::class, 'preset_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Everything waiting for an operator, oldest first — because a submission nobody has answered
     * is somebody waiting.
     *
     * @param  Builder<SignalDefinitionSubmission>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', SignalSubmissionStatus::Pending->value)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => SignalSubmissionStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalDefinitionSubmissionFactory
    {
        return SignalDefinitionSubmissionFactory::new();
    }
}
