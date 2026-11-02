<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Core\Enums\Market;
use App\Modules\Signals\Enums\SignalCategory;
use App\Modules\Signals\Enums\SignalIcon;
use App\Modules\Signals\Http\Requests\SaveSignalDefinitionRequest;
use App\Modules\Signals\Models\SignalDefinition;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated input for creating or updating a {@see SignalDefinition} (from
 * {@see SaveSignalDefinitionRequest}), per ADR 0018.
 *
 * Everything here is written by a customer and some of it reaches an AI prompt, so the clamping is
 * not decoration: every string is squished and truncated **in characters** (ADR 0017's rule for
 * prompt input applies to a definition's rules and templates as much as to an article), every list
 * is capped, and the two values the client turns into components — `category` and `icon` — are
 * enums rather than free strings.
 *
 * `rules()` sits beside the shape it validates so the web and API endpoints cannot drift on what a
 * definition may be, the same arrangement {@see SignalWatchData} uses.
 */
final class SignalDefinitionData extends Data
{
    public const int MAX_NAME = 120;

    public const int MAX_DESCRIPTION = 255;

    /** The storage key's width, matching `signals.event_key` exactly (see the S7 migrations). */
    public const int MAX_KEY = 32;

    /** Matches `SignalQueryBuilder::MAX_QUERY` — a template longer than a query it cannot become. */
    public const int MAX_TEMPLATE = 200;

    /**
     * Templates per definition. Each one is a paid search on every run of every watch using this
     * type, multiplied by the locations S8 adds — so the cap is a cost ceiling, not a form limit.
     */
    public const int MAX_TEMPLATES = 10;

    public const int MAX_KEYWORDS = 20;

    public const int MAX_KEYWORD = 60;

    public const int MAX_RULES = 10;

    public const int MAX_RULE = 200;

    public const int MAX_HINT = 255;

    /**
     * @param  list<string>  $queryTemplates
     * @param  list<string>  $matchKeywords
     * @param  list<string>  $includes
     * @param  list<string>  $excludes
     * @param  list<Market>|null  $markets  Null means "any market".
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $queryTemplates,
        public readonly SignalCategory $category = SignalCategory::Custom,
        public readonly SignalIcon $icon = SignalIcon::Satellite,
        public readonly array $matchKeywords = [],
        public readonly array $includes = [],
        public readonly array $excludes = [],
        public readonly ?int $minConfidence = null,
        public readonly ?int $minRelevance = null,
        public readonly ?string $suggestedApproachHint = null,
        public readonly ?array $markets = null,
        public readonly bool $isActive = true,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            name: self::text($validated['name'] ?? null, self::MAX_NAME) ?? 'Untitled signal',
            description: self::text($validated['description'] ?? null, self::MAX_DESCRIPTION) ?? '',
            queryTemplates: self::strings($validated['query_templates'] ?? null, self::MAX_TEMPLATES, self::MAX_TEMPLATE),
            category: SignalCategory::tryFrom((string) ($validated['category'] ?? '')) ?? SignalCategory::Custom,
            icon: SignalIcon::tryFrom((string) ($validated['icon'] ?? '')) ?? SignalIcon::Satellite,
            // Lower-cased, because the ICP match is a case-insensitive substring test and storing
            // them already-lowered keeps the comparison from having to re-learn that every run.
            matchKeywords: array_map(
                static fn (string $keyword): string => Str::lower($keyword),
                self::strings($validated['match_keywords'] ?? null, self::MAX_KEYWORDS, self::MAX_KEYWORD),
            ),
            includes: self::strings($validated['includes'] ?? null, self::MAX_RULES, self::MAX_RULE),
            excludes: self::strings($validated['excludes'] ?? null, self::MAX_RULES, self::MAX_RULE),
            minConfidence: self::score($validated['min_confidence'] ?? null),
            minRelevance: self::score($validated['min_relevance'] ?? null),
            suggestedApproachHint: self::text($validated['suggested_approach_hint'] ?? null, self::MAX_HINT),
            markets: self::markets($validated['markets'] ?? null),
            // A missing checkbox is absent rather than false in a form post, so "absent" has to
            // mean the default and not "switch it off" (the same reasoning as `SignalWatchData`).
            isActive: array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
        );
    }

    /**
     * The attributes for the model, ready to create or fill with.
     *
     * **The key is not here.** It is derived from the name once, on create, and never changed after
     * — every signal this definition has ever classified carries a copy of it in `event_key`, so
     * renaming the type must not orphan its own history. See `SaveSignalDefinitionAction`.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'icon' => $this->icon,
            'is_active' => $this->isActive,
            'query_templates' => $this->queryTemplates,
            'match_keywords' => $this->matchKeywords,
            'classification_rules' => [
                'includes' => $this->includes,
                'excludes' => $this->excludes,
            ],
            // Always both keys, both nullable: null means "use the product's floor", and leaving a
            // key out entirely would make "not set" and "cleared" the same stored shape.
            'thresholds' => [
                'min_confidence' => $this->minConfidence,
                'min_relevance' => $this->minRelevance,
            ],
            'suggested_approach_hint' => $this->suggestedApproachHint,
            'markets' => $this->markets === null
                ? null
                : array_map(static fn (Market $market): string => $market->value, $this->markets),
        ];
    }

    /**
     * The validation rules a saved definition must satisfy, beside the shape they validate.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::MAX_NAME],
            'description' => ['required', 'string', 'max:'.self::MAX_DESCRIPTION],
            'category' => ['required', 'string', 'in:'.implode(',', SignalCategory::values())],
            'icon' => ['required', 'string', 'in:'.implode(',', SignalIcon::values())],
            // At least one: a definition with no template is a type that can never find anything,
            // which is a worse outcome than a validation message.
            'query_templates' => ['required', 'array', 'min:1', 'max:'.self::MAX_TEMPLATES],
            'query_templates.*' => ['string', 'max:'.self::MAX_TEMPLATE],
            'match_keywords' => ['nullable', 'array', 'max:'.self::MAX_KEYWORDS],
            'match_keywords.*' => ['string', 'max:'.self::MAX_KEYWORD],
            'includes' => ['nullable', 'array', 'max:'.self::MAX_RULES],
            'includes.*' => ['string', 'max:'.self::MAX_RULE],
            'excludes' => ['nullable', 'array', 'max:'.self::MAX_RULES],
            'excludes.*' => ['string', 'max:'.self::MAX_RULE],
            'min_confidence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'min_relevance' => ['nullable', 'integer', 'min:0', 'max:100'],
            'suggested_approach_hint' => ['nullable', 'string', 'max:'.self::MAX_HINT],
            'markets' => ['nullable', 'array', 'max:'.count(Market::cases())],
            'markets.*' => ['string', 'distinct', 'in:'.implode(',', Market::values())],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * A storage key for a name: slugged, underscored and clamped to the column.
     *
     * Underscores rather than hyphens, to match v1's keys (`funding_round`) — the three presets are
     * the same rows after S7's migration and a team's own types should not read differently from
     * them. Uniqueness within the team is the Action's job, since only it can query.
     */
    public static function keyFor(string $name): string
    {
        $key = Str::slug($name, '_');

        if ($key === '') {
            $key = 'signal';
        }

        return Str::limit($key, self::MAX_KEY, '');
    }

    /**
     * A bounded, de-duplicated list of non-empty strings.
     *
     * @return list<string>
     */
    private static function strings(mixed $value, int $maxItems, int $maxLength): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $text = self::text($entry, $maxLength);

            if ($text !== null && ! in_array($text, $out, strict: true)) {
                $out[] = $text;
            }
        }

        return array_slice($out, 0, $maxItems);
    }

    /**
     * @return list<Market>|null
     */
    private static function markets(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            // Absent and empty both mean "any", which is how a hand-authored type starts: a team
            // that wrote their own type knows where they sell.
            return null;
        }

        $markets = [];

        foreach ($value as $entry) {
            $market = is_string($entry) ? Market::tryFrom($entry) : null;

            if ($market !== null && ! in_array($market, $markets, strict: true)) {
                $markets[] = $market;
            }
        }

        return $markets === [] ? null : $markets;
    }

    private static function score(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) $value));
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $squished = Str::squish($value);

        return $squished === '' ? null : Str::limit($squished, $max, '');
    }
}
