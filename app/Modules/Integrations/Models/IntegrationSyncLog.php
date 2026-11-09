<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Integrations\Enums\SyncOutcome;
use App\Modules\Integrations\Enums\SyncTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What happened, once per push (PL7).
 *
 * History rather than state: nothing reads it to decide anything, which is why it is the table that
 * may be pruned and {@see IntegrationLink} is not. It exists for the question a member actually
 * asks — "why is this company not in my CRM" — and the answer has to be able to be "because it is
 * on your do-not-contact list", which is why {@see SyncOutcome::Refused} is a row rather than a
 * silence.
 *
 * `error` holds a message we composed or a provider's, truncated by the caller. Never a token,
 * never a request body.
 *
 * @property int $id
 * @property int $team_id
 * @property int $integration_id
 * @property SyncTarget $local_type
 * @property int $local_id
 * @property SyncOutcome $outcome
 * @property string|null $error
 * @property Carbon|null $created_at
 */
class IntegrationSyncLog extends Model
{
    use BelongsToTeam;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'integration_id',
        'local_type',
        'local_id',
        'outcome',
        'error',
    ];

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_type' => SyncTarget::class,
            'local_id' => 'integer',
            'outcome' => SyncOutcome::class,
        ];
    }
}
