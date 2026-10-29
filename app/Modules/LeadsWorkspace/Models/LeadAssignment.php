<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\LeadSourcing\Models\Lead;
use Database\Factories\LeadAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Who is working a lead (Phase 10b). A row per (lead, member), because a lead can be shared — a
 * researcher and a closer on the same company is normal — and because it records who handed it over
 * and when. Tenant scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lead_id
 * @property int $user_id
 * @property int|null $assigned_by
 * @property Carbon $assigned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeadAssignment extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<LeadAssignmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'lead_id',
        'user_id',
        'assigned_by',
        'assigned_at',
    ];

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
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
            'assigned_at' => 'datetime',
        ];
    }

    protected static function newFactory(): LeadAssignmentFactory
    {
        return LeadAssignmentFactory::new();
    }
}
