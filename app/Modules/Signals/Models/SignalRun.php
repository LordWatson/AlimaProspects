<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Signals\Enums\SignalRunStatus;
use Database\Factories\SignalRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One execution of a {@see SignalWatch} (ADR 0011). Records the query/result/signal counts and the
 * metered cost (Serper + AI) in integer micro-units, mirroring `DiscoveryRun`. Tenant-scoped via
 * {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property int $signal_watch_id
 * @property SignalRunStatus $status
 * @property int $queries_count
 * @property int $results_count
 * @property int $signals_created
 * @property int $cost_micros
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalRun extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalRunFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'signal_watch_id',
        'status',
        'queries_count',
        'results_count',
        'signals_created',
        'cost_micros',
        'error',
        'started_at',
        'finished_at',
    ];

    /**
     * The watch this run belongs to.
     *
     * @return BelongsTo<SignalWatch, $this>
     */
    public function watch(): BelongsTo
    {
        return $this->belongsTo(SignalWatch::class, 'signal_watch_id');
    }

    /**
     * The signals this run kept.
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
            'status' => SignalRunStatus::class,
            'queries_count' => 'integer',
            'results_count' => 'integer',
            'signals_created' => 'integer',
            'cost_micros' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalRunFactory
    {
        return SignalRunFactory::new();
    }
}
