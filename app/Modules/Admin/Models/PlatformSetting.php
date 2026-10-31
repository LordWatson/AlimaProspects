<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Models\User;
use App\Modules\Admin\Services\PlatformSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An operator's override of a declared platform setting (Phase 19b).
 *
 * **Platform-wide, so no `BelongsToTeam`** — these are ours, not a tenant's, and the absence of the
 * trait is the statement. A row exists only where somebody has deviated from `config/admin.php`;
 * everything else answers from the declared default. Always read through {@see PlatformSettings},
 * never directly: the declared type is what a JSON value has to be cast back through, and the cache
 * in front of this table is on two hot paths.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlatformSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // `json`, not a scalar cast: a boolean has to come back a boolean and a list a list. The
        // declared `SettingType` is what narrows it the rest of the way.
        return [
            'value' => 'json',
        ];
    }
}
