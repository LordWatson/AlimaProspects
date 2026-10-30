<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\StageOutcome;
use Database\Factories\PipelineStageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One column of a team's kanban board (Phase 10b). Team-customisable — a team can rename, reorder,
 * add and remove stages — so nothing in the platform may key off a stage's **name**; `slug` is the
 * stable identity and `outcome` is what reporting reads. Tenant scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property int $position
 * @property bool $is_default
 * @property StageOutcome|null $outcome
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $leads_count
 */
class PipelineStage extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<PipelineStageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'position',
        'is_default',
        'outcome',
    ];

    /**
     * The leads sitting in this stage. Leads with **no** stage belong to the default one — see the
     * `pipeline_stage_id` migration — so this relation alone under-reports the default column; the
     * board query handles that case explicitly.
     *
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_default' => 'boolean',
            'outcome' => StageOutcome::class,
        ];
    }

    protected static function newFactory(): PipelineStageFactory
    {
        return PipelineStageFactory::new();
    }
}
