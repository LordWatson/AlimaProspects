<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Support\Security\SafeUrl;
use App\Modules\Signals\Enums\SignalCategory;
use App\Modules\Signals\Enums\SignalIcon;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Http\Requests\SaveSignalDefinitionRequest;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Rules\SafeFeedUrl;
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

    /** `signals.source_url` is `string(2048)`, and a feed URL is stored in json beside it. */
    public const int MAX_FEED_URL = 2048;

    /** Filing codes or categories one type may claim. More than this is a type doing two jobs. */
    public const int MAX_FILINGS = 25;

    /**
     * @param  list<string>  $queryTemplates
     * @param  list<string>  $matchKeywords
     * @param  list<string>  $includes
     * @param  list<string>  $excludes
     * @param  list<Market>|null  $markets  Null means "any market".
     * @param  list<SignalSourceKind>|null  $sourceKinds  Which sources this type runs on (S13,
     *                                                    ADR 0023). **Null means the caller did not
     *                                                    mention sources at all**, which is not the
     *                                                    same as choosing none: on create it means
     *                                                    news (the pre-S13 behaviour every existing
     *                                                    API client relies on), and on update it
     *                                                    means **leave the stored choice alone**.
     *                                                    Collapsing the two silently downgraded a
     *                                                    web-search type to news whenever a client
     *                                                    PATCHed it without the field (S13a review
     *                                                    finding 5).
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
        public readonly ?array $sourceKinds = null,
        /**
         * Per-source settings, keyed by source (S13b) — a feed's URL today, a filing map for the
         * registry. Null means "not mentioned", exactly as `$sourceKinds` does and for the same
         * compatibility reason: an update that omits it leaves the stored configuration alone.
         *
         * @var array<string, array<string, mixed>>|null
         */
        public readonly ?array $sourceConfig = null,
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
            // `array_key_exists`, not `??`: absent and empty are different requests, and only
            // the key's presence can tell them apart.
            sourceKinds: array_key_exists('source_keys', $validated)
                ? self::sources($validated['source_keys'])
                : null,
            sourceConfig: array_key_exists('source_config', $validated)
                ? self::sourceConfig($validated['source_config'])
                : null,
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
        $attributes = [
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

        // **Only when the caller mentioned sources.** Absent from the array rather than written as
        // news, so an update that does not mention them leaves the stored choice untouched — see
        // the constructor's note. A *create* that does not mention them leaves the column null,
        // which `SignalDefinition::sourceKinds()` reads as news, so the type still runs exactly
        // where a pre-S13 one did.
        if ($this->sourceKinds !== null) {
            $attributes['source_keys'] = array_map(
                static fn (SignalSourceKind $kind): string => $kind->value,
                $this->sourceKinds === [] ? [SignalSourceKind::default()] : $this->sourceKinds,
            );
        }

        // Same rule, same reason: absent means "leave the stored configuration alone", and an empty
        // array means "clear it" — which is a real request, because unticking a feed should not
        // leave its URL behind on the row.
        if ($this->sourceConfig !== null) {
            $attributes['source_config'] = $this->sourceConfig === [] ? null : $this->sourceConfig;
        }

        return $attributes;
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
            // **Only the sources that actually exist** (S13). `availableValues()` rather than
            // `values()`, because a declared-but-unoffered case has no provider behind it and a
            // type saved against one would contribute no queries and say nothing about why — the
            // same reason `SearchRegion`'s unoffered regions are not accepted either (ADR 0019 §2a).
            'source_keys' => ['nullable', 'array', 'min:1', 'max:'.count(SignalSourceKind::available())],
            'source_keys.*' => ['string', 'distinct', 'in:'.implode(',', SignalSourceKind::availableValues())],
            // Per-source settings (S13b). Only the shapes a source actually reads are validated;
            // anything else is dropped by `sourceConfig()` rather than rejected, so a client sending
            // a key for a source it does not know about is not an error.
            'source_config' => ['nullable', 'array'],
            'source_config.feed' => ['nullable', 'array'],
            // **`ActiveUrl` is deliberately not used.** The question is not "does this resolve" but
            // "may this application fetch it", which is `SafeUrl`'s — scheme, port and whether the
            // host lands on the public internet. A member deserves that answer beside the field
            // rather than from a worker three hours later.
            'source_config.feed.url' => ['nullable', 'string', 'max:'.self::MAX_FEED_URL, new SafeFeedUrl],
            'source_config.companies_house' => ['nullable', 'array'],
            'source_config.companies_house.filings' => ['nullable', 'array', 'max:'.self::MAX_FILINGS],
            'source_config.companies_house.filings.*' => ['string', 'distinct', 'max:64'],
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
     * Per-source settings, reduced to the shapes a source actually reads (S13b).
     *
     * **An allowlist, not a pass-through.** This column is json written from a request body, and a
     * provider is handed its own slice of it — so anything not named here is dropped rather than
     * stored. A caller cannot use it as free storage, and a future source cannot be configured by
     * accident before its code exists.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function sourceConfig(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        $feedUrl = self::text(
            is_array($value['feed'] ?? null) ? ($value['feed']['url'] ?? null) : null,
            self::MAX_FEED_URL,
        );

        if ($feedUrl !== null) {
            // Stored normalised, so the poll-interval floor and the conditional-GET cache — both
            // keyed on the URL — agree about what "the same feed" is whether somebody typed the
            // scheme or not.
            $out['feed'] = ['url' => SafeUrl::normalise($feedUrl) ?? $feedUrl];
        }

        $filings = is_array($value['companies_house'] ?? null)
            ? ($value['companies_house']['filings'] ?? null)
            : null;

        $codes = [];

        foreach (is_array($filings) ? $filings : [] as $code) {
            if (! is_string($code)) {
                continue;
            }

            // Lower-cased here as well as at lookup time, so the stored row reads the way the map
            // is keyed and a member who typed `AP01` sees why it matched.
            $clean = Str::lower(Str::squish($code));

            if ($clean !== '' && ! in_array($clean, $codes, strict: true)) {
                $codes[] = $clean;
            }
        }

        if ($codes !== []) {
            $out['companies_house'] = ['filings' => array_slice($codes, 0, self::MAX_FILINGS)];
        }

        return $out;
    }

    /**
     * The sources a saved type runs on, filtered to the ones this build can actually run.
     *
     * Filtered **again** here, after validation: the rules reject an unavailable key for a member
     * typing into a form, and this is what holds for a payload assembled anywhere else — ADR 0020's
     * habit of bounding the same thing in the schema and in the save path, because the two are
     * reached by different callers.
     *
     * @return list<SignalSourceKind>
     */
    private static function sources(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $kinds = [];

        foreach ($value as $entry) {
            $kind = SignalSourceKind::parse($entry);

            if ($kind !== null && $kind->isAvailable() && ! in_array($kind, $kinds, strict: true)) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
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
