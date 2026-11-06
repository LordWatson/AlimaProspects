<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Enums\PlaybookWatchlistCondition;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Services\SignalPlaybookEvaluator;

/**
 * What a signal has to look like for a playbook to fire (S14), narrowed out of the stored JSON.
 *
 * Every list here means "any of these", and an **empty list means "don't care"** rather than "none
 * of them". That asymmetry is worth stating because it is the opposite of what a naive
 * implementation does: a new rule with nothing filled in should match everything, which is what a
 * member building one expects to see in the preview before they start narrowing it.
 *
 * Nothing here is a free string the team typed except the jurisdiction codes, which are clamped to
 * two upper-case letters. `sources` is checked against {@see SignalSourceKind} on the way in, so a
 * rule cannot be saved against a source this build does not have.
 *
 * The matching itself lives in {@see SignalPlaybookEvaluator}, not here: this object is what a rule
 * says, and the evaluator is the one place that decides what saying it means.
 */
final readonly class SignalPlaybookConditions
{
    /** Most values a team may list for any one condition. A rule naming forty types is a rule. */
    public const int MAX_LIST = 25;

    /**
     * An amount ceiling in minor units, far beyond any deal this product will see. Bounded above
     * as well as below, because an unbounded integer in minor units is how a currency mistake
     * becomes a condition that matches nothing for ever.
     */
    public const int MAX_AMOUNT_MINOR = 100_000_000_000_000;

    /**
     * @param  list<int>  $definitionIds  Signal types; empty means any.
     * @param  list<string>  $sourceKinds  `SignalSourceKind` values; empty means any.
     * @param  list<string>  $jurisdictions  ISO-3166-1 alpha-2, upper case; empty means any.
     * @param  list<int>  $watchlistIds  Lists the watch must be pointed at; empty means any.
     */
    public function __construct(
        public array $definitionIds = [],
        public array $sourceKinds = [],
        public array $jurisdictions = [],
        /** 0-100. Null means no floor — which is **not** the same as a floor of zero (ADR 0022). */
        public ?int $minHeat = null,
        /** Minor units, inclusive. */
        public ?int $minAmountMinor = null,
        public ?int $maxAmountMinor = null,
        public PlaybookWatchlistCondition $watchlist = PlaybookWatchlistCondition::Any,
        public array $watchlistIds = [],
    ) {}

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        return new self(
            definitionIds: self::ids($stored['definitions'] ?? null),
            sourceKinds: self::sourceKinds($stored['sources'] ?? null),
            jurisdictions: self::jurisdictions($stored['jurisdictions'] ?? null),
            minHeat: self::bounded($stored['min_heat'] ?? null, 0, 100),
            minAmountMinor: self::amount($stored['min_amount_minor'] ?? null),
            maxAmountMinor: self::amount($stored['max_amount_minor'] ?? null),
            watchlist: PlaybookWatchlistCondition::tryFrom(
                is_string($stored['watchlist'] ?? null) ? $stored['watchlist'] : '',
            ) ?? PlaybookWatchlistCondition::Any,
            watchlistIds: self::ids($stored['watchlist_ids'] ?? null),
        );
    }

    /**
     * The shape stored on the row and sent to the screens.
     *
     * Written out in full, including the nulls and the empty lists, so a client reading it never
     * has to distinguish "not set" from "absent". The amount band is in minor units here as it is
     * everywhere (CLAUDE.md §2); the builder divides for display.
     *
     * @return array<string, mixed>
     */
    public function toStored(): array
    {
        return [
            'definitions' => $this->definitionIds,
            'sources' => $this->sourceKinds,
            'jurisdictions' => $this->jurisdictions,
            'min_heat' => $this->minHeat,
            'min_amount_minor' => $this->minAmountMinor,
            'max_amount_minor' => $this->maxAmountMinor,
            'watchlist' => $this->watchlist->value,
            'watchlist_ids' => $this->watchlistIds,
        ];
    }

    /**
     * Whether this rule narrows anything at all.
     *
     * The builder asks, because a rule that matches every signal and converts it is the single most
     * expensive mistake available on the screen, and the honest thing to do is say so before they
     * save rather than after the morning it ran.
     */
    public function matchesEverything(): bool
    {
        return $this->definitionIds === []
            && $this->sourceKinds === []
            && $this->jurisdictions === []
            && $this->minHeat === null
            && $this->minAmountMinor === null
            && $this->maxAmountMinor === null
            && $this->watchlist === PlaybookWatchlistCondition::Any
            && $this->watchlistIds === [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'conditions' => ['nullable', 'array'],
            'conditions.definitions' => ['nullable', 'array', 'max:'.self::MAX_LIST],
            'conditions.definitions.*' => ['integer', 'min:1'],
            // Only the sources this build actually runs. A declared-but-unavailable source would
            // make a rule that can never fire, which is worse than refusing it (ADR 0023 §3).
            'conditions.sources' => ['nullable', 'array', 'max:'.self::MAX_LIST],
            'conditions.sources.*' => [
                'string',
                'in:'.implode(',', array_map(
                    static fn (SignalSourceKind $kind): string => $kind->value,
                    SignalSourceKind::available(),
                )),
            ],
            'conditions.jurisdictions' => ['nullable', 'array', 'max:'.self::MAX_LIST],
            'conditions.jurisdictions.*' => ['string', 'size:2', 'alpha'],
            'conditions.min_heat' => ['nullable', 'integer', 'min:0', 'max:100'],
            'conditions.min_amount_minor' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT_MINOR],
            'conditions.max_amount_minor' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT_MINOR],
            'conditions.watchlist' => ['nullable', 'string', 'in:'.implode(',', PlaybookWatchlistCondition::values())],
            'conditions.watchlist_ids' => ['nullable', 'array', 'max:'.self::MAX_LIST],
            'conditions.watchlist_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * A bounded list of distinct positive ids.
     *
     * The ids are **not** checked against the team's own rows, which is the same treatment a
     * watch's ICP and a watchlist's stages get: an id from another team can only arrive from a
     * tampered form, and the evaluator simply never matches it. A rule that stopped firing because
     * somebody deleted one of three types would be the worse failure.
     *
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $entry) {
            if (! is_numeric($entry)) {
                continue;
            }

            $id = (int) $entry;

            if ($id > 0 && ! in_array($id, $ids, strict: true)) {
                $ids[] = $id;
            }

            if (count($ids) >= self::MAX_LIST) {
                break;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function sourceKinds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $kinds = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $kind = SignalSourceKind::tryFrom($entry);

            // An unavailable source is dropped rather than kept: the rule would never fire, and a
            // condition that silently cannot match is indistinguishable from a broken feature.
            if ($kind === null || ! $kind->isAvailable() || in_array($kind->value, $kinds, strict: true)) {
                continue;
            }

            $kinds[] = $kind->value;

            if (count($kinds) >= self::MAX_LIST) {
                break;
            }
        }

        return $kinds;
    }

    /**
     * @return list<string>
     */
    private static function jurisdictions(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $codes = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $code = strtoupper(trim($entry));

            if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || in_array($code, $codes, strict: true)) {
                continue;
            }

            $codes[] = $code;

            if (count($codes) >= self::MAX_LIST) {
                break;
            }
        }

        return $codes;
    }

    private static function bounded(mixed $value, int $min, int $max): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max($min, min($max, (int) $value));
    }

    private static function amount(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $amount = (int) $value;

        return $amount < 0 ? null : min($amount, self::MAX_AMOUNT_MINOR);
    }
}
