<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Models\User;
use App\Modules\Compliance\Enums\LiaStatus;
use App\Modules\Compliance\Services\LegitimateInterestGate;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use Database\Factories\LiaRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A team's recorded Legitimate Interest Assessment for one market (docs/compliance.md §2). Tenant
 * scoped via {@see BelongsToTeam}; one row per (team, market), because a team trading in both the
 * UK and the UAE is working under two regimes and one assessment cannot honestly cover both
 * (ADR 0007).
 *
 * This is the lawful basis on which every lead the team sources is allowed to exist, so
 * {@see LegitimateInterestGate} refuses discovery without one in force — not merely logs its
 * absence.
 *
 * **In force** is narrower than "active": the status says the team affirmed it, and
 * {@see self::isInForce()} additionally asks whether the review date still holds. An assessment is
 * a judgement about a balance that moves, so it expires rather than standing for ever.
 *
 * @property int $id
 * @property int $team_id
 * @property Market $market
 * @property LiaStatus $status
 * @property string $purpose
 * @property string $necessity
 * @property string $balancing
 * @property string|null $safeguards
 * @property string|null $signals_scope
 * @property int|null $completed_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $review_due_at
 * @property Carbon|null $review_notified_at
 * @property int $version
 * @property Carbon|null $withdrawn_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LiaRecord extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<LiaRecordFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'market',
        'status',
        'purpose',
        'necessity',
        'balancing',
        'safeguards',
        'signals_scope',
        'completed_by',
        'completed_at',
        'review_due_at',
        'version',
        'withdrawn_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => LiaStatus::Draft->value,
        'version' => 1,
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Who signed it off.
     *
     * @return BelongsTo<User, $this>
     */
    public function signatory(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Whether this assessment currently supports processing — the question the discovery gate asks.
     *
     * Three conditions, all of them necessary: affirmed, not withdrawn, and not past its review
     * date. A record with no `review_due_at` is treated as in force, which only happens for a row
     * written before the review window existed.
     */
    public function isInForce(?Carbon $at = null): bool
    {
        if ($this->status !== LiaStatus::Active || $this->completed_at === null) {
            return false;
        }

        if ($this->withdrawn_at !== null) {
            return false;
        }

        return $this->review_due_at === null
            || $this->review_due_at->isAfter($at ?? Carbon::now());
    }

    /**
     * Affirmed, but past the date the team said they would look at it again. Distinguished from
     * "never signed off" because the member needs a different sentence: re-affirm what is there,
     * rather than write it from scratch.
     */
    public function hasExpired(?Carbon $at = null): bool
    {
        return $this->status === LiaStatus::Active
            && $this->withdrawn_at === null
            && $this->review_due_at !== null
            && ! $this->review_due_at->isAfter($at ?? Carbon::now());
    }

    /**
     * Days until the review falls due — negative once it has. Null when there is no date to count
     * to, or the assessment is not active.
     */
    public function reviewDueInDays(?Carbon $at = null): ?int
    {
        if ($this->status !== LiaStatus::Active || $this->review_due_at === null) {
            return null;
        }

        $from = $at ?? Carbon::now();

        return (int) ceil($from->diffInDays($this->review_due_at, absolute: false));
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'status' => LiaStatus::class,
            'completed_at' => 'datetime',
            'review_due_at' => 'datetime',
            'review_notified_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected static function newFactory(): LiaRecordFactory
    {
        return LiaRecordFactory::new();
    }
}
