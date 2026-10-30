<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Models\Team;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Services\SuppressionList;
use Database\Factories\OutreachSuppressionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry on the do-not-contact list (docs/compliance.md §3.2–3.3).
 *
 * **Deliberately not {@see BelongsToTeam}.** A global entry has no team,
 * and the whole point of a global entry is that every team's query finds it — a tenant global scope
 * would hide exactly the rows that must never be missed. Reads go through
 * {@see SuppressionList}, which scopes by scope rather than by tenancy, and the policy is what
 * stops a team seeing or editing another team's own list.
 *
 * Only the hash is matched. `value_preview` exists so a team can recognise its own entries; there
 * is no column holding the address in the clear.
 *
 * @property int $id
 * @property int|null $team_id
 * @property int|null $created_by
 * @property SuppressionScope $scope
 * @property string $scope_key
 * @property SuppressionType $type
 * @property string $value_hash
 * @property string $value_preview
 * @property SuppressionReason $reason
 * @property string|null $note
 * @property Carbon $suppressed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OutreachSuppression extends Model
{
    /** @use HasFactory<OutreachSuppressionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'created_by',
        'scope',
        'scope_key',
        'type',
        'value_hash',
        'value_preview',
        'reason',
        'note',
        'suppressed_at',
    ];

    /**
     * The team whose own list this entry is on; null for a global entry.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether this entry may be lifted from the product. A global opt-out may not: removing it
     * would let a tenant overrule a person's stated wish with a button.
     */
    public function isRemovable(): bool
    {
        return $this->scope->isTeamManaged();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'scope' => SuppressionScope::class,
            'type' => SuppressionType::class,
            'reason' => SuppressionReason::class,
            'suppressed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OutreachSuppressionFactory
    {
        return OutreachSuppressionFactory::new();
    }
}
