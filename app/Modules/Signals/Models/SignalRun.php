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
 * @property int|null $queries_requested What the plan wanted before the ceiling trimmed it (S8)
 * @property int $results_count
 * @property int $signals_created
 * @property int $cost_micros
 * @property array<array-key, mixed>|null $source_breakdown Written by `SignalSourceBreakdown`; read back defensively, because what is in the column was written by whichever build stored it.
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
        'queries_requested',
        'results_count',
        'signals_created',
        'cost_micros',
        'source_breakdown',
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
     * How many searches the ceiling cut from this run, or zero.
     *
     * `queries_requested` is nullable because a run from before S8 never recorded it; null reads as
     * "nobody counted" rather than as a run that wanted nothing, so it reports no trim.
     */
    public function queriesTrimmed(): int
    {
        if ($this->queries_requested === null) {
            return 0;
        }

        return max(0, $this->queries_requested - $this->queries_count);
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
            'queries_requested' => 'integer',
            'results_count' => 'integer',
            'signals_created' => 'integer',
            'cost_micros' => 'integer',
            'source_breakdown' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalRunFactory
    {
        return SignalRunFactory::new();
    }
}
