<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use Database\Factories\LeadActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One entry on a {@see Lead}'s timeline (Phase 10): what happened, when, who did it, and a
 * polymorphic `subject` pointing at whatever it was about (the run that found the lead, the score
 * it was given, a note). Append-only — an activity records the past, so nothing edits a row.
 * Tenant scoped via {@see BelongsToTeam}.
 *
 * `properties` holds structured values, never a formed sentence: the workspace is EN/AR (ADR 0007),
 * so the wording is composed at render time from `type` + `properties`.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lead_id
 * @property int|null $user_id
 * @property LeadActivityType $type
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $properties
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeadActivity extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<LeadActivityFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'lead_id',
        'user_id',
        'type',
        'subject_type',
        'subject_id',
        'properties',
        'occurred_at',
    ];

    /**
     * The lead this entry belongs to.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The member who did it, or null when the platform did it on its own.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whatever the entry is about — a run, a score, a note. Null for entries about the lead itself.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => LeadActivityType::class,
            'properties' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function newFactory(): LeadActivityFactory
    {
        return LeadActivityFactory::new();
    }
}
