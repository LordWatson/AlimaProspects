<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Enums\Market;
use App\Modules\Signals\Enums\SignalCategory;
use App\Modules\Signals\Enums\SignalIcon;
use App\Modules\Signals\Services\PlatformPresetReader;
use Database\Factories\SignalDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * What a signal type *is* (ADR 0018, S7): the replacement for v1's three-case `SignalEventType`
 * enum. Carries its own name, explainer, query templates, ICP keyword map, classification rules and
 * thresholds — all of which were already data, which is the whole argument for the row.
 *
 * **Two scopes live in this one table.** A row with a `team_id` is the team's own definition,
 * tenant-scoped via {@see BelongsToTeam} like everything else. A row with `team_id = null` is a
 * **platform preset**, readable by every team and writable only by the platform. The global scope
 * hides presets from a team-scoped query, and that is correct: the only thing allowed to read them
 * is {@see PlatformPresetReader}, which is the one place in the module that removes the scope
 * (ADR 0018 §2). A team *clones* a preset rather than pointing at one, so a platform edit never
 * silently changes what a team is paying to run.
 *
 * @property int $id
 * @property int|null $team_id
 * @property int $scope_id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property SignalCategory $category
 * @property SignalIcon $icon
 * @property bool $is_active
 * @property list<string> $query_templates
 * @property list<string>|null $match_keywords
 * @property array{includes?: list<string>, excludes?: list<string>}|null $classification_rules
 * @property array{min_confidence?: int|null, min_relevance?: int|null}|null $thresholds
 * @property string|null $suggested_approach_hint
 * @property list<string>|null $markets
 * @property string|null $preset_key
 * @property int|null $preset_version
 * @property int $version
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class SignalDefinition extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalDefinitionFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * The `scope_id` that means "platform preset" (ADR 0018 §6).
     *
     * `scope_id` is a **stored generated column** — `coalesce(team_id, 0)` — so it is read-only
     * here and always agrees with `team_id`. It exists so `unique(scope_id, key)` can say "one key
     * per scope", which `unique(team_id, key)` cannot: MySQL treats NULLs in a unique index as
     * distinct, so two presets could have shared a key and the SQLite suite would never have
     * noticed (`memory/sqlite-tests-mysql-prod.md`).
     */
    public const int PLATFORM_SCOPE = 0;

    /**
     * Set while writing a platform preset, so {@see BelongsToTeam}'s auto-assign can be undone.
     *
     * The trait assigns the active team on create when `team_id` is null, which is right for every
     * other tenant model and wrong for exactly this one: a preset has no team by definition, and
     * the code that writes one may well run with a team active (a test, a tinker session, the admin
     * panel in S15). The flag is explicit rather than inferred so nothing depends on *hoping* no
     * team is set.
     */
    public bool $isPlatformPreset = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'key',
        'name',
        'description',
        'category',
        'icon',
        'is_active',
        'query_templates',
        'match_keywords',
        'classification_rules',
        'thresholds',
        'suggested_approach_hint',
        'markets',
        'preset_key',
        'preset_version',
        'version',
        'created_by',
    ];

    protected static function booted(): void
    {
        // Registered here rather than in a trait so it runs **after** BelongsToTeam's own
        // `creating` listener (traits boot first), which is the only order in which undoing its
        // auto-assign works.
        //
        // `scope_id` is deliberately **not** touched here. It is a stored generated column
        // (`coalesce(team_id, 0)`) because it carries a uniqueness invariant, and this hook is
        // exactly the kind of thing that cannot be trusted with one: `Event::fake()` suppresses
        // Eloquent events, and a bulk `insert()` never fires them at all. See the migration.
        static::creating(static function (self $definition): void {
            if ($definition->isPlatformPreset) {
                $definition->setAttribute('team_id', null);
            }
        });
    }

    /**
     * The signals this definition has classified. Not a cascade: the FK is `nullOnDelete` so a
     * signal outlives its definition (ADR 0018 §4).
     *
     * @return HasMany<Signal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    /**
     * The watches that watch for this type. A pivot rather than a JSON column so this question is
     * one join on both engines (ADR 0018 §5) — it is the query the delete refusal asks.
     *
     * @return BelongsToMany<SignalWatch, $this>
     */
    public function watches(): BelongsToMany
    {
        return $this->belongsToMany(SignalWatch::class, 'signal_watch_definition')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPreset(): bool
    {
        return $this->team_id === null;
    }

    /**
     * Whether this definition still carries Alima's own wording, rather than the team's.
     *
     * It decides one thing, and it decides it for every screen: whether the name and description go
     * through `t()`. A preset's wording is our English and must be translated for an Arabic UI
     * (ADR 0007); a team's own wording is theirs, in whichever language they wrote it, and
     * translating it would be the app overwriting what they typed.
     *
     * The rule is "cloned, and never edited since": `version` is bumped on every save, so a clone
     * at version 1 is one nobody has touched. A team that renames their copy owns the name from
     * that moment on, which is the behaviour anybody would expect and the only one that does not
     * need a second column to record.
     */
    public function usesPlatformWording(): bool
    {
        return $this->isPreset() || ($this->preset_key !== null && $this->version === 1);
    }

    /**
     * Whether this definition is worth running in a given market.
     *
     * `markets = null` means "any", which is how a hand-authored type starts: a team that wrote
     * their own type knows where they sell, and refusing to run it because they did not tick a box
     * would be the app second-guessing them.
     */
    public function appliesToMarket(Market $market): bool
    {
        $markets = $this->markets;

        if ($markets === null || $markets === []) {
            return true;
        }

        return in_array($market->value, $markets, strict: true);
    }

    /**
     * The confidence and relevance floors a signal of this type has to clear, falling back to the
     * global `signals.*` config where the definition sets none.
     *
     * Null on a definition means "whatever the product's floor is", not "no floor" — so tuning the
     * global setting still moves every definition that has not been tuned itself.
     *
     * @return array{min_confidence: int, min_relevance: int}
     */
    public function effectiveThresholds(): array
    {
        $thresholds = $this->thresholds ?? [];

        $confidence = $thresholds['min_confidence'] ?? null;
        $relevance = $thresholds['min_relevance'] ?? null;

        return [
            'min_confidence' => $this->clampScore(
                is_numeric($confidence) ? (int) $confidence : (int) config('signals.min_confidence', 60),
            ),
            'min_relevance' => $this->clampScore(
                is_numeric($relevance) ? (int) $relevance : (int) config('signals.min_relevance', 40),
            ),
        ];
    }

    /**
     * What counts as this kind of event, for the extraction prompt.
     *
     * @return list<string>
     */
    public function includes(): array
    {
        return $this->ruleList('includes');
    }

    /**
     * What does not — the half that does the work, because the noise this feature fights is
     * round-ups, trackers and listicles that read exactly like the real thing.
     *
     * @return list<string>
     */
    public function excludes(): array
    {
        return $this->ruleList('excludes');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'category' => SignalCategory::class,
            'icon' => SignalIcon::class,
            'is_active' => 'boolean',
            'query_templates' => 'array',
            'match_keywords' => 'array',
            'classification_rules' => 'array',
            'thresholds' => 'array',
            'markets' => 'array',
            'preset_version' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function newFactory(): SignalDefinitionFactory
    {
        return SignalDefinitionFactory::new();
    }

    /**
     * @return list<string>
     */
    private function ruleList(string $key): array
    {
        $rules = $this->classification_rules ?? [];

        $out = [];

        // Blank entries are dropped rather than trusted: the DTO squishes and drops them on the way
        // in, but a row written by a seeder or a migration has not been through the DTO.
        foreach ($rules[$key] ?? [] as $rule) {
            $trimmed = trim($rule);

            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }

    private function clampScore(int $score): int
    {
        return max(0, min(100, $score));
    }
}
