<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

use App\Modules\Core\Enums\Market;
use App\Modules\Signals\Enums\SignalCategory;
use App\Modules\Signals\Enums\SignalIcon;
use App\Modules\Signals\Models\SignalDefinition;

/**
 * One platform signal-definition preset as {@see SignalPresetCatalogue} declares it (ADR 0018 §2),
 * before it is a row.
 *
 * A plain value object rather than a `Data` DTO: nothing validates a preset, because nothing
 * outside this codebase authors one. What it earns is {@see self::toRow()} — one mapping from a
 * declared preset to the columns of a {@see SignalDefinition}, so the seeder and any future writer
 * cannot disagree about what a preset is.
 */
final readonly class SignalPreset
{
    /**
     * @param  string  $key  The stable storage key. Also the `event_key` written onto every signal
     *                       a clone of this preset produces, so it outlives the row.
     * @param  list<string>  $queryTemplates  Plain-keyword Serper templates; `{location}` is
     *                                        substituted at query-build time.
     * @param  list<string>  $matchKeywords  Lower-case fragments that map an ICP buying signal onto
     *                                       this type (substring match — see `SignalQueryBuilder`).
     * @param  list<string>  $includes  What counts, in the model's words.
     * @param  list<string>  $excludes  What does not, which is the half that does the work.
     * @param  list<Market>|null  $markets  The markets this preset makes sense in, or null for any.
     * @param  int|null  $minConfidence  Per-definition floor, or null to use `signals.min_confidence`.
     * @param  int|null  $minRelevance  Per-definition floor, or null to use `signals.min_relevance`.
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public SignalCategory $category,
        public SignalIcon $icon,
        public array $queryTemplates,
        public array $matchKeywords = [],
        public array $includes = [],
        public array $excludes = [],
        public ?array $markets = null,
        public ?int $minConfidence = null,
        public ?int $minRelevance = null,
        public ?string $suggestedApproachHint = null,
        public int $version = 1,
        /**
         * Whether the one-click first watch should include this type.
         *
         * The library grows — S9 takes it to six vertical packs — and the one-click path cannot
         * clone all of it: a team would land past `max_definitions_per_team` and pay for a search
         * per template per type on every run. So "the broadest useful first watch" is a **declared
         * starter set**, not "everything", and it stays small as the library does not.
         */
        public bool $isStarter = true,
    ) {}

    /**
     * The columns of the platform row this preset becomes.
     *
     * `team_id` is null, which *is* "platform preset" (ADR 0018 §2). `scope_id` is **not** here:
     * it is a stored generated column the database computes from `team_id`, so writing it would be
     * writing to a generated column.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'team_id' => null,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category->value,
            'icon' => $this->icon->value,
            'is_active' => true,
            'query_templates' => $this->queryTemplates,
            'match_keywords' => $this->matchKeywords,
            'classification_rules' => [
                'includes' => $this->includes,
                'excludes' => $this->excludes,
            ],
            'thresholds' => [
                'min_confidence' => $this->minConfidence,
                'min_relevance' => $this->minRelevance,
            ],
            'suggested_approach_hint' => $this->suggestedApproachHint,
            'markets' => $this->markets === null
                ? null
                : array_map(static fn (Market $market): string => $market->value, $this->markets),
            // A preset is not itself a clone, so it carries no provenance of one.
            'preset_key' => null,
            'preset_version' => null,
            'version' => $this->version,
            'created_by' => null,
        ];
    }
}
