<?php

declare(strict_types=1);

namespace App\Modules\ICP\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use Database\Factories\IcpFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An Ideal Customer Profile: a team's named, weighted definition of who they want to find and
 * qualify. Owns its {@see IcpCriterion} rows (weighted signals + exclusions). A team may keep
 * several saved ICPs but exactly one is active at a time — the active one drives discovery and
 * scoring. Tenant scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, IcpCriterion> $criteria
 */
class Icp extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<IcpFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'name',
        'description',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => false,
    ];

    /**
     * The criteria that make up this ICP, in display order.
     *
     * @return HasMany<IcpCriterion, $this>
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(IcpCriterion::class)->orderBy('position');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): IcpFactory
    {
        return IcpFactory::new();
    }
}
