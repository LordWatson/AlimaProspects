<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Support\CompanyNameKey;
use App\Modules\Signals\Enums\WatchlistEntityType;
use Database\Factories\SignalWatchlistEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One company or person on a watchlist (S10).
 *
 * `normalised_name` is the comparable form, and it comes from
 * {@see CompanyNameKey} — the **same** rule `SourcedLead::dedupeKey()` uses. That is deliberate and
 * was the plan's instruction: a third name-matching normaliser is how two leads become three.
 *
 * `lead_id` is nullable and both states are useful. The pipeline sync fills it, so an event about
 * that company lands on that lead. A name a member typed may have no lead at all — watching the
 * company you are *trying* to win is as reasonable as watching the one you have.
 *
 * @property int $id
 * @property int $team_id
 * @property int $signal_watchlist_id
 * @property WatchlistEntityType $entity_type
 * @property string $name
 * @property string $normalised_name
 * @property list<string>|null $aliases
 * @property string|null $domain
 * @property string|null $company_number The registry's identity, or null where none is established (S13b).
 * @property Carbon|null $company_number_checked_at When we last **tried** — the negative cache.
 * @property int|null $lead_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalWatchlistEntry extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalWatchlistEntryFactory> */
    use HasFactory;

    /** Matches the column, and longer than any real company name. */
    public const int MAX_NAME = 160;

    /** Aliases per entry. Each one is another paid search on every run, so it is a cost ceiling. */
    public const int MAX_ALIASES = 5;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'signal_watchlist_id',
        'entity_type',
        'name',
        'normalised_name',
        'aliases',
        'domain',
        'company_number',
        'company_number_checked_at',
        'lead_id',
        'is_active',
    ];

    /**
     * @return BelongsTo<SignalWatchlist, $this>
     */
    public function watchlist(): BelongsTo
    {
        return $this->belongsTo(SignalWatchlist::class, 'signal_watchlist_id');
    }

    /**
     * The lead this entry is, where the team's own pipeline put it here.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * Every way this entry's subject might be written, the member's spelling first.
     *
     * Capped and de-duplicated here rather than trusted from the column, because each one is a paid
     * search on every run of every watch using the list.
     *
     * @return list<string>
     */
    public function searchNames(): array
    {
        $names = [Str::squish($this->name)];

        foreach ($this->aliases ?? [] as $alias) {
            $clean = Str::squish($alias);

            if ($clean !== '' && ! in_array($clean, $names, strict: true)) {
                $names[] = $clean;
            }
        }

        return array_slice(array_values(array_filter($names, static fn (string $name): bool => $name !== '')), 0, self::MAX_ALIASES + 1);
    }

    /**
     * Every comparable key this entry answers to, for the matcher.
     *
     * The strict key for each name plus the bare one, so an entry reading "Acme Engineering Ltd"
     * matches an article saying "Acme Engineering" without the matcher having to know about legal
     * forms itself.
     *
     * @return list<string>
     */
    public function matchKeys(): array
    {
        $keys = [];

        foreach ($this->searchNames() as $name) {
            foreach ([CompanyNameKey::for($name), CompanyNameKey::bare($name)] as $key) {
                if ($key !== '' && ! in_array($key, $keys, strict: true)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * Normalise on the way in, so nothing can store an entry whose key disagrees with its name.
     *
     * In the model rather than only in the Action, and **not** in a `creating` hook: S7 learned that
     * the hard way — `Event::fake()` suppresses model events, so a hook maintaining a column that
     * carries a uniqueness invariant silently stopped running in the whole test suite. A mutator is
     * part of the assignment itself and cannot be switched off.
     */
    public function setNameAttribute(string $value): void
    {
        $clean = Str::limit(Str::squish($value), self::MAX_NAME, '');

        $this->attributes['name'] = $clean;
        $this->attributes['normalised_name'] = Str::limit(CompanyNameKey::for($clean), self::MAX_NAME, '');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_type' => WatchlistEntityType::class,
            'aliases' => 'array',
            'is_active' => 'boolean',
            'company_number_checked_at' => 'datetime',
        ];
    }

    /**
     * Whether the registry has been asked about this entry yet (S13b).
     *
     * The timestamp rather than the number, because "asked and not found" is a real and common
     * answer — a trading name, a person, an overseas business — and re-asking it on every run for
     * ever is the cost this column exists to avoid.
     */
    public function companyNumberResolved(): bool
    {
        return $this->company_number_checked_at !== null;
    }

    /**
     * Whether this entry can be looked up in the UK registry at all.
     *
     * A **person** cannot: Companies House indexes companies, and an officer appears only inside a
     * company's own record. So a person entry is never searched, which also keeps the resolver from
     * spending a request to learn that each time.
     */
    public function isRegistryAddressable(): bool
    {
        return $this->entity_type === WatchlistEntityType::Company;
    }

    protected static function newFactory(): SignalWatchlistEntryFactory
    {
        return SignalWatchlistEntryFactory::new();
    }
}
