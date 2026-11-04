<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Signals\Enums\WatchlistEntityType;
use App\Modules\Signals\Enums\WatchlistSourceKind;
use Database\Factories\SignalWatchlistFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A set of named accounts a team wants watched (S10).
 *
 * The phase's whole argument in one object: v1 asked *who just came into money?*, a cold question
 * about a stranger. A watchlist asks *what just happened to the companies I am already working?* —
 * which has a contact, a relationship and a reason to call today. It is also what makes the feature
 * hard to switch off: a discovery feed is optional, a monitor over your own pipeline is not, unless
 * you would rather not know.
 *
 * Three kinds (`WatchlistSourceKind`) and only two offered: a `pipeline` list is mirrored from the
 * team's own leads overnight, a `manual` one is exactly what somebody typed, and `segment` is
 * declared but refused until a saved view's filters can be replayed on a schedule.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property WatchlistSourceKind $source_kind
 * @property bool $is_active
 * @property array{stages?: list<int>, owners?: list<int>}|null $sync_settings
 * @property int $entries_count
 * @property Carbon|null $synced_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalWatchlist extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalWatchlistFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'name',
        'source_kind',
        'is_active',
        'sync_settings',
        'entries_count',
        'synced_at',
        'created_by',
    ];

    /**
     * Everything on the list, newest first.
     *
     * @return HasMany<SignalWatchlistEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(SignalWatchlistEntry::class, 'signal_watchlist_id');
    }

    /**
     * The entries a run actually searches for.
     *
     * Paused entries stay on the list: a member switching one off is saying "not this one for now",
     * and deleting it would lose the `lead_id` the pipeline sync worked out.
     *
     * @return HasMany<SignalWatchlistEntry, $this>
     */
    public function activeEntries(): HasMany
    {
        return $this->entries()->where('is_active', true)->orderBy('id');
    }

    /**
     * The watches pointed at this list.
     *
     * @return HasMany<SignalWatch, $this>
     */
    public function watches(): HasMany
    {
        return $this->hasMany(SignalWatch::class, 'signal_watchlist_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The pipeline stages this list mirrors, or an empty list for "every stage".
     *
     * @return list<int>
     */
    public function syncStages(): array
    {
        /** @var list<int> $stages */
        $stages = array_values(array_filter(
            array_map(
                static fn (mixed $id): int => (int) $id,
                (array) ($this->sync_settings['stages'] ?? []),
            ),
            static fn (int $id): bool => $id > 0,
        ));

        return $stages;
    }

    /**
     * The members whose leads this list mirrors, or an empty list for "everyone's".
     *
     * @return list<int>
     */
    public function syncOwners(): array
    {
        /** @var list<int> $owners */
        $owners = array_values(array_filter(
            array_map(
                static fn (mixed $id): int => (int) $id,
                (array) ($this->sync_settings['owners'] ?? []),
            ),
            static fn (int $id): bool => $id > 0,
        ));

        return $owners;
    }

    /**
     * Recompute the cached `entries_count` for one or more lists, from the rows that now exist.
     *
     * **A cached counter is a second write, and two S16 delete paths forgot it** (`/code-review`):
     * the DSAR erasure of a watched person and the retention sweep of an orphaned entry both issue
     * a bulk `DELETE` and neither maintained the count. `RemoveWatchlistEntryAction`'s docblock has
     * always said this is "**two** writes — the row, and the list's cached `entries_count`"; what it
     * could not say is what a *third* and *fourth* caller should do, so this is that, in one place.
     *
     * The sweep's case is the one that matters, because it can never self-heal: it exists precisely
     * for the **paused** synced list `SyncPipelineWatchlistAction` returns early on, which is the
     * one path that never recomputes. A list stuck reporting forty accounts with twenty-eight rows
     * is a screen that lies and a `SignalWatchController` that keeps offering an empty list,
     * because that one filters on `entries_count > 0`.
     *
     * Recomputed with a `count()` rather than decremented, for the reason the other callers already
     * use one: a figure derived from the rows cannot drift, and a decrement applied twice can.
     * Scoped by `team_id` as well as by key, and silent about a key that is not this team's.
     *
     * Takes any int-keyed array of ids rather than a `list`, because it normalises one itself — a
     * `Collection::map()->all()` keeps its source keys, and demanding a list here would push that
     * detail onto every caller for no gain.
     *
     * @param  array<int, int>  $ids
     * @return int how many lists were recounted
     */
    public static function recountEntries(array $ids, ?int $teamId = null): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return 0;
        }

        $lists = self::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->when($teamId !== null, static fn ($query) => $query->where('team_id', $teamId))
            ->get();

        foreach ($lists as $list) {
            $list->forceFill([
                'entries_count' => SignalWatchlistEntry::query()
                    ->withoutGlobalScopes()
                    ->where('signal_watchlist_id', $list->getKey())
                    ->count(),
            ])->save();
        }

        return $lists->count();
    }

    /**
     * Whether a scheduled sync should touch this list.
     *
     * A paused list is left exactly as it is rather than emptied: pausing is "stop searching", not
     * "forget what I told you".
     */
    public function isSyncable(): bool
    {
        return $this->is_active && $this->source_kind->isSynced();
    }

    /**
     * Whether a member may edit the entries by hand.
     *
     * A synced list's entries are an output, and letting somebody delete one that the next sync
     * would put straight back is a screen that argues with itself. They pause the list, or narrow
     * its stages.
     */
    public function allowsManualEntries(): bool
    {
        return $this->source_kind === WatchlistSourceKind::Manual;
    }

    /**
     * How many of each kind are on it, for the screen's one-line summary.
     *
     * @return array<string, int>
     */
    public function countsByType(): array
    {
        $counts = [];

        foreach (WatchlistEntityType::cases() as $type) {
            $counts[$type->value] = 0;
        }

        foreach ($this->entries as $entry) {
            $counts[$entry->entity_type->value]++;
        }

        return $counts;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_kind' => WatchlistSourceKind::class,
            'is_active' => 'boolean',
            'sync_settings' => 'array',
            'entries_count' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalWatchlistFactory
    {
        return SignalWatchlistFactory::new();
    }
}
