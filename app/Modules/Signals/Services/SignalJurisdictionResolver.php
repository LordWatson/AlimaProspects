<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Enums\Market;
use App\Modules\Signals\Enums\SearchRegion;
use Illuminate\Support\Str;

/**
 * Turns the AI's free-text `location` into an ISO-3166-1 alpha-2 country code (S8, ADR 0019).
 *
 * **Deterministic, and no AI.** This is the input to a legal rule — it decides which outreach
 * restriction a converted lead carries — so it is a config place map (`signals.jurisdictions`) and
 * nothing inferred. The model is already guessing once when it reads a snippet; asking it to guess
 * again about jurisdiction would stack two guesses under a compliance flag.
 *
 * Three properties matter, and each has a reason:
 *
 * - **Whole-token matching.** "Dubai" must match "Dubai, UAE" and "Dubai-based" and must not be
 *   found inside "Mumbai". A substring test would quietly file an Indian funding round under the
 *   UAE and stamp a PDPL restriction on it.
 * - **Longest alias first, across every country.** "New South Wales" must not be decided by
 *   "Wales", and that only holds if the comparison is global: an earlier version sorted each
 *   country's own aliases by length and still iterated the countries in config order, so whichever
 *   country happened to list "wales" first won. The aliases are flattened into one list sorted
 *   longest-first, so the most specific place wins however the config is arranged.
 * - **Null when unsure.** A place the map does not know returns null, and null is **not**
 *   "unrestricted" — `ConvertSignalToLeadAction` falls back to the watch and then to the most
 *   restrictive of its regions, so an unknown place fails toward the restriction. That is the
 *   direction to fail in, and it is why this class never guesses.
 *
 * **An ambiguous place resolves to the most restrictive candidate.** When the text genuinely names
 * more than one country the map knows ("UK founder relocates to Dubai"), the winner is the one
 * whose regime restricts outreach the most, and only then is the watch's own region order consulted
 * to settle candidates that are equally restrictive. The first version asked the watch first, which
 * meant a watch listing `gb` before `ae` resolved that sentence to the United Kingdom — and because
 * `ConvertSignalToLeadAction` takes a resolved market as final, that produced a lead about somebody
 * moving to Dubai carrying **no** restriction at all. Two places named is an ambiguity, and the one
 * rule this class has is that an ambiguity fails toward the restriction.
 *
 * **Specificity outranks restrictiveness**, which is the subtlety that makes those two rules live
 * together. "New South Wales" matches AU on `new south wales` and GB on `wales`, and that is not
 * two places being named — it is one alias sitting inside another. A match whose alias is contained
 * in another match's alias is dropped as the artefact it is, *before* restrictiveness is consulted.
 * Without that step the more restrictive of an artefact pair would win, and the UAE's `ras al
 * khaimah` or GB's `london` would start swallowing longer names from any country added later.
 */
final class SignalJurisdictionResolver
{
    /** Longer than any sane `location` the prompt allows; a guard rather than a feature. */
    private const int MAX_TEXT = 255;

    /**
     * The country code for a signal's stated location, or null when the map cannot tell.
     *
     * @param  list<SearchRegion>  $regions  The watch's regions, used only to break a tie.
     */
    public function resolve(?string $location, array $regions = []): ?string
    {
        $matches = $this->matches($location);

        if ($matches === []) {
            return null;
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        // More than one place named, so this is an ambiguity and the direction to fail in is toward
        // the restriction: keep only the candidates whose regime restricts outreach the most.
        $strictest = max(array_map(self::restrictiveness(...), $matches));
        $candidates = array_values(array_filter(
            $matches,
            static fn (string $code): bool => self::restrictiveness($code) === $strictest,
        ));

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // Equally restrictive candidates. *Now* the watch's own regions are a legitimate tiebreak —
        // a watch over Dubai that catches two UAE places means the one it was looking for — because
        // whichever wins carries the same restriction either way.
        foreach ($regions as $region) {
            $code = Str::upper($region->value);

            if (in_array($code, $candidates, strict: true)) {
                return $code;
            }
        }

        // Neither is the watch's. There is no principled winner left, so the rule is simply
        // **reproducible** — the most specific alias that matched, then config order among
        // equal-length ones. An arbitrary answer would make the same article resolve differently
        // between runs, and this feeds a compliance flag.
        return $candidates[0];
    }

    /**
     * The compliance market for a signal's stated location, or null.
     *
     * A code with no `Market` behind it (`SG`, `QA`, …) resolves to null rather than to something
     * approximate: the app holds a position on two jurisdictions and inventing a third here would
     * be a compliance claim made by a lookup table (ADR 0019 §3).
     *
     * @param  list<SearchRegion>  $regions
     */
    public function resolveMarket(?string $location, array $regions = []): ?Market
    {
        $code = $this->resolve($location, $regions);

        return $code === null ? null : self::marketFor($code);
    }

    /**
     * How much a country code's regime restricts outreach, as a sortable rank.
     *
     * Asked rather than hard-coded, the same way `ConvertSignalToLeadAction::mostRestrictive()`
     * asks: a restriction that forbids direct marketing outranks one that permits it, which
     * outranks a regime with no restriction, which outranks a country the business holds no
     * position on at all. A restriction added later is ranked by what it forbids rather than by
     * where somebody remembered to put it in a list.
     */
    private static function restrictiveness(string $code): int
    {
        $market = self::marketFor($code);

        if (! $market instanceof Market) {
            return 0;
        }

        $restriction = $market->outreachRestriction();

        if ($restriction === null) {
            return 1;
        }

        return $restriction->allowsDirectMarketing() ? 2 : 3;
    }

    /**
     * The compliance market a country code maps onto, or null where none is recorded.
     */
    public static function marketFor(string $code): ?Market
    {
        return match (Str::upper(trim($code))) {
            'AE' => Market::Uae,
            'GB' => Market::Uk,
            default => null,
        };
    }

    /**
     * Every country code the text names, most specific first, de-duplicated.
     *
     * @return list<string>
     */
    private function matches(?string $location): array
    {
        if (! is_string($location)) {
            return [];
        }

        $text = Str::lower(Str::limit(Str::squish($location), self::MAX_TEXT, ''));

        if ($text === '') {
            return [];
        }

        /** @var list<array{0: string, 1: string}> $hits [code, the alias that matched] */
        $hits = [];
        $placed = [];

        foreach ($this->aliases() as [$alias, $code]) {
            if (isset($placed[$code])) {
                // One alias is enough to place a country; the rest of its list adds nothing.
                continue;
            }

            if ($this->containsToken($text, $alias)) {
                $placed[$code] = true;
                $hits[] = [$code, $alias];
            }
        }

        return $this->withoutOverlaps($hits);
    }

    /**
     * Drop any hit whose alias sits inside another hit's alias.
     *
     * "New South Wales" places AU on `new south wales` and GB on `wales`, and treating that as two
     * countries being named would hand the sentence to whichever of them is more restrictive. It is
     * one place, matched twice. The longer alias is the more specific statement about the text, so
     * the shorter one is discarded — and because {@see aliases()} is sorted longest-first, the
     * specific hit is always the one already in the list.
     *
     * @param  list<array{0: string, 1: string}>  $hits
     * @return list<string>
     */
    private function withoutOverlaps(array $hits): array
    {
        $kept = [];

        foreach ($hits as [$code, $alias]) {
            foreach ($hits as [$otherCode, $otherAlias]) {
                if ($otherCode !== $code && $otherAlias !== $alias && str_contains($otherAlias, $alias)) {
                    continue 2;
                }
            }

            $kept[] = $code;
        }

        return $kept;
    }

    /**
     * Every (alias, country code) pair in the config map, sorted **longest alias first**.
     *
     * Flat and globally sorted rather than grouped by country, which is the correction to the first
     * version of this: sorting each country's own aliases and then iterating the countries in
     * config order meant "wales" (GB) was tested before "new south wales" (AU), so New South Wales
     * resolved to the United Kingdom. Equal-length aliases keep config order, which is what makes
     * the tiebreak reproducible.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function aliases(): array
    {
        /** @var array<string, mixed> $configured */
        $configured = (array) config('signals.jurisdictions', []);

        /** @var list<array{0: string, 1: string}> $pairs */
        $pairs = [];
        $seen = [];

        foreach ($configured as $code => $aliases) {
            if (! is_array($aliases)) {
                continue;
            }

            $country = Str::upper(trim((string) $code));

            foreach ($aliases as $alias) {
                if (! is_string($alias)) {
                    continue;
                }

                $clean = Str::lower(Str::squish($alias));

                if ($clean === '' || isset($seen[$clean])) {
                    continue;
                }

                $seen[$clean] = true;
                $pairs[] = [$clean, $country];
            }
        }

        // Stable by construction: `usort` is not stable, so the index is the tiebreak that keeps
        // equal-length aliases in config order.
        $indexed = [];
        foreach ($pairs as $index => $pair) {
            $indexed[] = [$pair, $index];
        }

        usort($indexed, static function (array $a, array $b): int {
            $byLength = mb_strlen($b[0][0]) <=> mb_strlen($a[0][0]);

            return $byLength !== 0 ? $byLength : $a[1] <=> $b[1];
        });

        return array_map(static fn (array $entry): array => $entry[0], $indexed);
    }

    /**
     * Whether `$needle` appears in `$haystack` as a whole token.
     *
     * A token boundary here is "not a letter or a digit", which is what makes "Dubai-based",
     * "Dubai," and "(Dubai)" all match while "Mumbai" does not. Deliberately **not** `\b`: an alias
     * may legitimately end in a full stop (`u.s.`), and `\b` after a `.` never matches.
     */
    private function containsToken(string $haystack, string $needle): bool
    {
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';

        return preg_match($pattern, $haystack) === 1;
    }
}
