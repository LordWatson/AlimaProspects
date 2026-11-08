<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Support;

use App\Modules\Scoring\Services\DeterministicScorer;
use Illuminate\Support\Str;

/**
 * Places a free-text industry on a canonical **sector**, which is what a literal string compare
 * cannot do. An ICP written "SaaS" and a lead enriched as "cloud software development" are the same
 * business and share not one word, so {@see DeterministicScorer} asks
 * this instead of `str_contains`: when both sides land on the same sector it is a confident match,
 * when they land on different sectors a confident miss, and when either is unmappable the scorer
 * defers to the AI half rather than guessing.
 *
 * The map lives in `config/scoring.php` (`sectors`), so a team's market can grow it without a code
 * change. The service is pure over that config: same text in, same sector out.
 */
final class SectorTaxonomy
{
    /**
     * The canonical sector a piece of industry text belongs to, or null when the vocabulary isn't
     * one the taxonomy carries. The first sector whose keywords appear wins, so more specific
     * sectors are ordered above the broader ones in config.
     */
    public function sectorOf(?string $text): ?string
    {
        $haystack = $this->normalise((string) $text);

        if ($haystack === '') {
            return null;
        }

        foreach ($this->sectors() as $key => $keywords) {
            foreach ($keywords as $keyword) {
                if ($this->contains($haystack, $this->normalise($keyword))) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Whole-token containment: " software " is in " cloud software development " but "it" is not in
     * "security". Both sides are already normalised to single-spaced lower case, so padding with
     * spaces turns a substring test into a word-boundary one without a regex per keyword.
     */
    private function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return str_contains(' '.$haystack.' ', ' '.$needle.' ');
    }

    /**
     * Lower-case, punctuation to spaces, squished — the same shape the scorer matches in, so the
     * two never disagree about what "E-commerce." and "ecommerce" are.
     */
    private function normalise(string $value): string
    {
        $stripped = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return Str::squish(Str::lower($stripped ?? $value));
    }

    /**
     * @return array<string, list<string>>
     */
    private function sectors(): array
    {
        /** @var array<array-key, mixed> $configured */
        $configured = (array) config('scoring.sectors', []);

        $out = [];

        foreach ($configured as $key => $keywords) {
            if (! is_string($key) || ! is_array($keywords)) {
                continue;
            }

            $words = [$key];

            foreach ($keywords as $keyword) {
                if (is_string($keyword) && trim($keyword) !== '') {
                    $words[] = $keyword;
                }
            }

            $out[$key] = array_values(array_unique($words));
        }

        return $out;
    }
}
