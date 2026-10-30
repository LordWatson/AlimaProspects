<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Models\User;
use App\Modules\Compliance\Services\DataRetention;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Models\Team;
use Database\Factories\RetentionPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One team's data-retention policy (docs/compliance.md §3.4). Tenant scoped via
 * {@see BelongsToTeam}, one row per team.
 *
 * **A team with no row is still swept.** {@see DataRetention} falls back to the configured
 * defaults, so storage limitation applies from the first day rather than from the first visit to
 * the settings screen. The row records a team's *departure* from the default — including switching
 * the sweep off, which is deliberately possible: a team under a litigation hold must be able to
 * stop deletion, and one that cannot will avoid the product instead.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $updated_by
 * @property bool $is_enabled
 * @property int $uncontacted_lead_days
 * @property int $contacted_lead_days
 * @property Carbon|null $last_swept_at
 * @property int $last_deleted_count
 * @property int $last_anonymised_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RetentionPolicy extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<RetentionPolicyFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'updated_by',
        'is_enabled',
        'uncontacted_lead_days',
        'contacted_lead_days',
        'last_swept_at',
        'last_deleted_count',
        'last_anonymised_count',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'uncontacted_lead_days' => 'integer',
            'contacted_lead_days' => 'integer',
            'last_swept_at' => 'datetime',
            'last_deleted_count' => 'integer',
            'last_anonymised_count' => 'integer',
        ];
    }

    protected static function newFactory(): RetentionPolicyFactory
    {
        return RetentionPolicyFactory::new();
    }
}
