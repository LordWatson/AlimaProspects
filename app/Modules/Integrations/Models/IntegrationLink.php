<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Integrations\Enums\SyncTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "Their id for our row", plus what we last sent them (PL7).
 *
 * This table is the difference between a sync and a duplicate factory, and it is the one of the
 * three that must never be pruned: lose a link and the next push creates the organisation again, in
 * somebody else's CRM, where we cannot clean it up.
 *
 * `synced_hash` is a hash of the fields the connector sent, not of the lead. It answers "has
 * anything the CRM can see changed", which is the only question worth asking before a push —
 * `updated_at` moves when somebody adds a note, and a nightly sync keyed on that would re-push
 * every unchanged organisation a team owns.
 *
 * @property int $id
 * @property int $team_id
 * @property int $integration_id
 * @property SyncTarget $local_type
 * @property int $local_id
 * @property string $external_type the provider's own noun — organization, company, person, contact
 * @property string $external_id
 * @property string $synced_hash
 * @property Carbon $synced_at
 */
class IntegrationLink extends Model
{
    use BelongsToTeam;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'integration_id',
        'local_type',
        'local_id',
        'external_type',
        'external_id',
        'synced_hash',
        'synced_at',
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
            'synced_at' => 'datetime',
        ];
    }
}
