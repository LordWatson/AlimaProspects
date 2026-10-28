<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Models\User;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A tenant. Users may belong to many teams and switch the active one (ADR 0006).
 *
 * @property int $id
 * @property string $name
 * @property string $timezone
 * @property string $locale
 * @property string $market
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'timezone',
        'locale',
        'market',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'UTC',
        'locale' => 'en',
        'market' => 'uk',
    ];

    /**
     * Members of the team, with their team-scoped role on the pivot.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }
}
