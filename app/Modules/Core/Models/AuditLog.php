<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable record of security- and privacy-relevant actions (ADR 0006, compliance).
 * Append-only: never updated or deleted in normal flows.
 *
 * @property int $id
 * @property int|null $team_id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $properties
 * @property string|null $ip_address
 * @property string|null $request_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AuditLog extends Model
{
    use BelongsToTeam;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'properties',
        'ip_address',
        'request_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }
}
