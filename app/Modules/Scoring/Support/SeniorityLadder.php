<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Support;

use App\Modules\Scoring\Services\DeterministicScorer;
use Illuminate\Support\Str;

/**
 * Turns a seniority criterion and a contact's title into comparable **ranks**, so "Director or
 * above" matches a "CEO" — which a text compare never could, the two sharing no words.
 *
 * A criterion names a *floor* (the lowest rank the team will accept) and a title is ranked on the
 * same ladder. {@see DeterministicScorer} then reads it plainly: a
 * title at or above the floor matches, a ranked title below it is a confident miss, and a title the
 * ladder can't place defers to the AI half. Higher number = more senior. The ladder lives in
 * `config/scoring.php` (`seniority`).
 */
final class SeniorityLadder
{
    /**
     * The rank a contact title sits on (higher = more senior), or null when no rung's keyword
     * appears. Ranks are tried most-senior first, so "managing director" is a 5 and never caught by
     * the "director" keyword a rung lower.
     */
    public function rankOf(?string $title): ?int
    {
        $haystack = $this->normalise((string) $title);

        if ($haystack === '') {
            return null;
        }

        $ranks = $this->ranks();
        krsort($ranks);

        foreach ($ranks as $rank => $keywords) {
            foreach ($keywords as $keyword) {
                if ($this->contains($haystack, $this->normalise($keyword))) {
                    return $rank;
                }
            }
        }

        return null;
    }

    /**
     * The rank a Seniority criterion names as its floor, or null when it names no recognisable
     * level (e.g. "decision maker") — in which case the scorer falls back to a literal match.
     * Longer floor phrases are tried first so "vice president" beats "president".
     */
    public function floorOf(string $criterionValue): ?int
    {
        $haystack = $this->normalise($criterionValue);

        if ($haystack === '') {
            return null;
        }

        $floors = $this->floors();

        uksort($floors, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($floors as $phrase => $rank) {
            if ($this->contains($haystack, $this->normalise($phrase))) {
                return $rank;
            }
        }

        return null;
    }

    private function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return str_contains(' '.$haystack.' ', ' '.$needle.' ');
    }

    private function normalise(string $value): string
    {
        $stripped = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return Str::squish(Str::lower($stripped ?? $value));
    }

    /**
     * @return array<int, list<string>>
     */
    private function ranks(): array
    {
        /** @var array<array-key, mixed> $configured */
        $configured = (array) config('scoring.seniority.ranks', []);

        $out = [];

        foreach ($configured as $rank => $keywords) {
            if (! is_int($rank) || ! is_array($keywords)) {
                continue;
            }

            $out[$rank] = array_values(array_filter(
                array_map(static fn (mixed $k): string => is_string($k) ? $k : '', $keywords),
                static fn (string $k): bool => trim($k) !== '',
            ));
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function floors(): array
    {
        /** @var array<array-key, mixed> $configured */
        $configured = (array) config('scoring.seniority.floors', []);

        $out = [];

        foreach ($configured as $phrase => $rank) {
            if (is_string($phrase) && is_int($rank) && trim($phrase) !== '') {
                $out[$phrase] = $rank;
            }
        }

        return $out;
    }
}
