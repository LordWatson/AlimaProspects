<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Enums\Market;
use App\Modules\ICP\Models\Icp;
use App\Modules\Signals\Enums\SearchRegion;
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
 * SQLite (ADR 0018 section 5).
 *
 * Since S8 the single `location` is `locations` (a list) and the implicit market-to-`gl` mapping
 * is `regions` (a list of {@see SearchRegion}). `market` stays, demoted to the team's **declared
 * regime** for this watch: it seeds defaults and backstops a signal whose own jurisdiction cannot
 * be resolved, and it no longer decides where to look (ADR 0019). Those were two questions one
 * column was answering.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $icp_id
 * @property string $name
 * @property Market $market The team's declared regime for this watch - a backstop, not the `gl` (S8).
 * @property list<string>|null $locations
 * @property list<string>|null $regions
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
        'locations',
        'regions',
        'ignore_domains',
        'signal_watchlist_id',
        'entity_cursor',
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
     * The regions this watch searches, as enum cases, dropping any that is no longer **offered**.
     *
     * `offered` is a compliance switch rather than a feature flag (ADR 0019 section 3): a region
     * switched off because the business has taken no position on its law has to stop being
     * searched, including for the watches that already named it. Falls back to the region matching
     * the watch's own market, which is what the S8 backfill gave every v1 watch.
     *
     * @return list<SearchRegion>
     */
    public function searchRegions(): array
    {
        $regions = [];

        foreach ($this->regions ?? [] as $value) {
            $region = SearchRegion::tryFrom($value);

            if ($region !== null && $region->isOffered() && ! in_array($region, $regions, strict: true)) {
                $regions[] = $region;
            }
        }

        return $regions === [] ? [SearchRegion::forMarket($this->market)] : $regions;
    }

    /**
     * Every region stored on this watch, offered or not, de-duplicated.
     *
     * The difference from {@see searchRegions()} is the whole point of having both. `offered` is a
     * compliance switch over what a watch may *search from now on*, so a region switched off has to
     * stop costing money. It must **not** stop restricting: a signal this watch already found under
     * `ae` was found there whatever the config says today, and dropping that region from the
     * restriction backstop would quietly turn a UAE lead into an unrestricted one — the exact
     * fail-permissive direction ADR 0019 section 3 forbids. So the restriction reads this, and only
     * the query builder reads the filtered list.
     *
     * No fallback to the watch's market here: {@see candidateMarkets()} includes that already, and a
     * fallback would make "stored" mean something it doesn't.
     *
     * @return list<SearchRegion>
     */
    public function storedRegions(): array
    {
        $regions = [];

        foreach ($this->regions ?? [] as $value) {
            $region = SearchRegion::tryFrom($value);

            if ($region !== null && ! in_array($region, $regions, strict: true)) {
                $regions[] = $region;
            }
        }

        return $regions;
    }

    /**
     * The markets this watch could produce leads under, for the restriction backstop.
     *
     * Only the regions that map to a regime the business holds contribute; one with none adds
     * nothing, because the app cannot say what the rules are there (ADR 0019 section 3). The
     * watch's own market is always included, since it is the team's declared answer.
     *
     * Reads {@see storedRegions()} rather than {@see searchRegions()} — see there for why switching
     * a region off must not relax a restriction.
     *
     * @return list<Market>
     */
    public function candidateMarkets(): array
    {
        $markets = [$this->market];

        foreach ($this->storedRegions() as $region) {
            $market = $region->market();

            if ($market !== null && ! in_array($market, $markets, strict: true)) {
                $markets[] = $market;
            }
        }

        return $markets;
    }

    /**
     * The watchlist this watch is pointed at, when it watches accounts rather than places (S10).
     *
     * A watch is **either** topical or entity-scoped, and the presence of this is the distinction —
     * no `kind` column, because a `kind` that could disagree with the list would be a second source
     * of truth about the same thing.
     *
     * @return BelongsTo<SignalWatchlist, $this>
     */
    public function watchlist(): BelongsTo
    {
        return $this->belongsTo(SignalWatchlist::class, 'signal_watchlist_id');
    }

    /** Whether this watch searches for named accounts rather than for places. */
    public function isEntityScoped(): bool
    {
        return $this->signal_watchlist_id !== null;
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
            'locations' => 'array',
            'regions' => 'array',
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
