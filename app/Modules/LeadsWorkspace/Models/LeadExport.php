<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\LeadsWorkspace\Data\LeadFilters;
use App\Modules\LeadsWorkspace\Enums\ExportPreset;
use App\Modules\LeadsWorkspace\Enums\ExportState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One request to take leads out as a file (PL6). Tenant scoped via {@see BelongsToTeam}.
 *
 * The instruction, the artefact and the receipt on one row — see the migration's note. Nothing
 * here is fillable except the instruction: a row's `path`, `row_count` and `state` are what the
 * generator did, and a request that could assert them would be a request claiming a file exists.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property ExportPreset $preset
 * @property list<string>|null $columns
 * @property array<string, mixed> $filters
 * @property ExportState $state
 * @property int $row_count
 * @property int $suppressed_count
 * @property string|null $path on the private disk; never a route parameter
 * @property string|null $reservation PL4's allowance handle, held from the moment it was asked for
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeadExport extends Model
{
    use BelongsToTeam;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'user_id',
        'preset',
        'columns',
        'filters',
        'state',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The filters this export was asked for, rebuilt from the stored params.
     *
     * Rebuilt rather than serialised, for the reason the migration gives: a queued export must
     * still run after a deploy that changed the DTO.
     */
    public function toFilters(): LeadFilters
    {
        return LeadFilters::fromArray($this->filters);
    }

    /**
     * The columns, in order: the preset's, or the member's own for a custom one.
     *
     * @return list<string>
     */
    public function columnKeys(): array
    {
        if ($this->preset !== ExportPreset::Custom) {
            return $this->preset->columns();
        }

        return $this->columns ?? ExportPreset::LeadsOnly->columns();
    }

    /**
     * Whether this file can be handed over right now.
     *
     * `expires_at` is checked here as well as by the signed URL, because the two answer different
     * questions: the signature says the link was not tampered with, and this says the file has not
     * been pruned out from under it.
     */
    public function isDownloadable(): bool
    {
        return $this->state === ExportState::Ready
            && $this->path !== null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'preset' => ExportPreset::class,
            'state' => ExportState::class,
            'columns' => 'array',
            'filters' => 'array',
            'row_count' => 'integer',
            'suppressed_count' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
