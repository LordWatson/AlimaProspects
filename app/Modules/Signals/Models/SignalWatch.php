<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Enums\Market;
use App\Modules\ICP\Models\Icp;
use App\Modules\Signals\Enums\SignalWatchCadence;
use App\Modules\Signals\Services\SignalQueryBuilder;
use Database\Factories\SignalWatchFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What a team watches for (ADR 0011): a named set of {@see SignalDefinition} types in a
 * market/location, optionally shaped by an {@see Icp}, run on a {@see SignalWatchCadence}.
 * Each run is a {@see SignalRun}; the events kept are {@see Signal}s. Tenant-scoped via
 * {@see BelongsToTeam}.
 *
 * The types used to be a JSON list of `SignalEventType` values on this row. Since S7 they are rows
 * of their own, reached through the `signal_watch_definition` pivot — a pivot rather than a JSON
 * column of ids because "which watches use this definition?" is then one join on both MySQL and
 * SQLite (ADR 0018 §5).
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $icp_id
 * @property string $name
 * @property Market $market
 * @property string $location
 * @property list<string>|null $ignore_domains
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
        'ignore_domains',
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
     * The signal types this watch watches for.
     *
     * Ordered by definition id, which is the order a team's clones were created in — and therefore
     * the order {@see SignalQueryBuilder} resolves an ambiguous ICP
     * buying signal in. v1 relied on the enum's declaration order for the same determinism; this is
     * where that promise now lives.
     *
     * @return BelongsToMany<SignalDefinition, $this>
     */
    public function definitions(): BelongsToMany
    {
        return $this->belongsToMany(SignalDefinition::class, 'signal_watch_definition')
            ->withTimestamps()
            ->orderBy('signal_definitions.id');
    }

    /**
     * The types this watch will actually run: its definitions, minus any the team has since paused.
     *
     * A paused definition stays attached on purpose. Un-pausing it should put the watch back the
     * way it was, rather than making the member remember which watches used to use it.
     *
     * @return Collection<int, SignalDefinition>
     */
    public function activeDefinitions(): Collection
    {
        $this->loadMissing('definitions');

        return $this->definitions
            ->filter(static fn (SignalDefinition $definition): bool => $definition->is_active)
            ->values();
    }

    /**
     * The keys this watch watches for, for a log line or an audit row.
     *
     * @return list<string>
     */
    public function eventKeys(): array
    {
        $this->loadMissing('definitions');

        return array_values($this->definitions
            ->map(static fn (SignalDefinition $definition): string => $definition->key)
            ->all());
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
            'ignore_domains' => 'array',
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
