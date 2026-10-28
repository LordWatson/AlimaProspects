<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Enums\TeamRole;
use Database\Factories\TeamInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pending invitation for a person (by email) to join a team with a given role.
 * Tenant-scoped (team_id) via {@see BelongsToTeam}. Status is derived, not stored:
 * accepted when `accepted_at` is set, expired when `expires_at` has passed.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $invited_by
 * @property string $email
 * @property string $role
 * @property string $token
 * @property Carbon|null $accepted_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TeamInvitation extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<TeamInvitationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'invited_by',
        'email',
        'role',
        'token',
        'accepted_at',
        'expires_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function roleEnum(): TeamRole
    {
        return TeamRole::tryFrom($this->role) ?? TeamRole::Member;
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TeamInvitationFactory
    {
        return TeamInvitationFactory::new();
    }
}
