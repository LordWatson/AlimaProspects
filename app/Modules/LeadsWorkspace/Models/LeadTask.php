<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadTaskStatus;
use Database\Factories\LeadTaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something a member has to do about a lead (Phase 10b), with an optional due date and a separate
 * reminder time — "remind me the day before" being the common case. Every user reference is nulled
 * rather than cascaded, so an open task doesn't vanish because the person it was given to left.
 * Tenant scoped via {@see BelongsToTeam}.
 *
 * Notifications (Phase 12) will sweep `remind_at`; `reminded_at` is already here so that sweep can
 * be idempotent from the first release rather than needing a migration to become so.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lead_id
 * @property int|null $created_by
 * @property int|null $assigned_to
 * @property string $title
 * @property LeadTaskStatus $status
 * @property Carbon|null $due_at
 * @property Carbon|null $remind_at
 * @property Carbon|null $reminded_at
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeadTask extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<LeadTaskFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'lead_id',
        'created_by',
        'assigned_to',
        'title',
        'status',
        'due_at',
        'remind_at',
        'reminded_at',
        'completed_at',
        'completed_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => LeadTaskStatus::Open->value,
    ];

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The member it is waiting on, or null when it belongs to the team rather than a person.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Still waiting on someone.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', LeadTaskStatus::Open);
    }

    /**
     * Past its due date and not done. Read in the team's timezone by the UI; the comparison itself
     * is in UTC, which is how the column is stored.
     */
    public function isOverdue(): bool
    {
        return $this->status === LeadTaskStatus::Open
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadTaskStatus::class,
            'due_at' => 'datetime',
            'remind_at' => 'datetime',
            'reminded_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): LeadTaskFactory
    {
        return LeadTaskFactory::new();
    }
}
