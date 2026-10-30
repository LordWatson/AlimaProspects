<?php

declare(strict_types=1);

namespace App\Modules\ICP\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\ICP\Enums\CriterionType;
use Database\Factories\IcpCriterionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rule inside an {@see Icp}: a typed {@see CriterionType} with its matched `value`. Positive
 * signals carry a `weight` (1–100 relative importance) the deterministic scorer normalises;
 * exclusions have a null weight and act as hard disqualifiers. Tenant scoped via {@see BelongsToTeam}
 * so direct queries are team-safe even though it is always reached through its ICP.
 *
 * @property int $id
 * @property int $team_id
 * @property int $icp_id
 * @property CriterionType $type
 * @property string $value
 * @property int|null $weight relative importance 1–100; null for exclusions
 * @property int $position
 */
class IcpCriterion extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<IcpCriterionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'icp_id',
        'type',
        'value',
        'weight',
        'position',
    ];

    /**
     * @return BelongsTo<Icp, $this>
     */
    public function icp(): BelongsTo
    {
        return $this->belongsTo(Icp::class);
    }

    public function isExclusion(): bool
    {
        return $this->type->isExclusion();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => CriterionType::class,
            'weight' => 'integer',
            'position' => 'integer',
        ];
    }

    protected static function newFactory(): IcpCriterionFactory
    {
        return IcpCriterionFactory::new();
    }
}
