<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\LeadSourcing\Models\Lead;
use Database\Factories\LeadNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a member wrote about a lead (Phase 10b). The author is nulled rather than cascaded on
 * delete: a note is the team's record of a conversation and must outlive the account that wrote it.
 * Tenant scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lead_id
 * @property int|null $user_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeadNote extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<LeadNoteFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'lead_id',
        'user_id',
        'body',
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

    protected static function newFactory(): LeadNoteFactory
    {
        return LeadNoteFactory::new();
    }
}
