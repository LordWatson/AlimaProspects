<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Enums\Market;
use App\Modules\ICP\Models\Icp;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Enums\SignalWatchCadence;
use Database\Factories\SignalWatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What a team watches for (ADR 0011): a named set of {@see SignalEventType}
 * events in a market/location, optionally shaped by an {@see Icp}, run on a {@see SignalWatchCadence}.
 * Each run is a {@see SignalRun}; the events kept are {@see Signal}s. Tenant-scoped via
 * {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $icp_id
 * @property string $name
 * @property Market $market
 * @property string $location
 * @property list<string> $event_types
 * @property SignalWatchCadence $cadence
 * @property bool $is_active
 * @property Carbon|null $last_run_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalWatch extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalWatchFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'icp_id',
        'name',
        'market',
        'location',
        'event_types',
        'cadence',
        'is_active',
        'last_run_at',
    ];

    /**
     * The ICP that shapes this watch, if any.
     *
     * @return BelongsTo<Icp, $this>
     */
    public function icp(): BelongsTo
    {
        return $this->belongsTo(Icp::class);
    }

    /**
     * The runs this watch has produced.
     *
     * @return HasMany<SignalRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(SignalRun::class);
    }

    /**
     * The signals this watch has yielded across all its runs.
     *
     * @return HasMany<Signal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'event_types' => 'array',
            'cadence' => SignalWatchCadence::class,
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalWatchFactory
    {
        return SignalWatchFactory::new();
    }
}
