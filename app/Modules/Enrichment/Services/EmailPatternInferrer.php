<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Enrichment\Data\InferredContact;
use Illuminate\Support\Str;

/**
 * Builds **one** candidate email per named person from a company domain and the configured pattern
 * (Q: a name on the page, but no email). This is the free-tier version of what a paid enrichment API
 * sells — and it is honest about its limits: the MVP has no SMTP probe (ADR 0008), so it cannot tell
 * `jane.smith@` from `j.smith@` or `jane@`, which is exactly why it emits the **single** most common
 * pattern rather than a spray of guesses. The caller verifies the domain accepts mail and stores the
 * result flagged as inferred with a capped confidence.
 *
 * Pure and network-free: given a domain and people it returns candidates and nothing else, so it can
 * run (and be tested) without DNS. It infers nothing from a single-token name — `{last}` would be
 * empty and `jane@` is as likely as any other guess, so a lone first name is not enough to justify
 * one — and it de-duplicates so two people who collapse to the same address yield one candidate.
 *
 * The pattern (`enrichment.inferred_email_pattern`) uses `{first}`, `{last}`, `{f}`, `{l}` tokens; an
 * unknown token is dropped and a pattern that renders to nothing produces no candidate.
 */
final class EmailPatternInferrer
{
    /**
     * Candidate inferred contacts for the people on `$domain`, capped at
     * `enrichment.max_inferred_contacts`.
     *
     * @param  list<array{name: string, title: ?string}>  $people
     * @return list<InferredContact>
     */
    public function infer(string $domain, array $people): array
    {
        $domain = $this->normaliseDomain($domain);

        if ($domain === null || $people === []) {
            return [];
        }

        $pattern = (string) config('enrichment.inferred_email_pattern', '{first}.{last}');
        $max = max(0, (int) config('enrichment.max_inferred_contacts', 3));

        if ($max === 0) {
            return [];
        }

        $out = [];
        /** @var list<string> $seen */
        $seen = [];

        foreach ($people as $person) {
            if (count($out) >= $max) {
                break;
            }

            $local = $this->localPart($pattern, $person['name']);

            if ($local === null) {
                continue;
            }

            $email = $local.'@'.$domain;

            if (in_array($email, $seen, strict: true)) {
                continue;
            }

            $title = $person['title'];

            $seen[] = $email;
            $out[] = new InferredContact(
                email: $email,
                name: trim($person['name']),
                title: $title !== null ? trim($title) : null,
            );
        }

        return $out;
    }

    /**
     * Render the local part from the pattern and a person's name, or null when the name has too
     * little to work with (no last name for a `{last}`-bearing pattern, or nothing renders).
     */
    private function localPart(string $pattern, string $name): ?string
    {
        $parts = $this->nameParts($name);

        if ($parts === null) {
            return null;
        }

        [$first, $last] = $parts;

        $replacements = [
            '{first}' => $first,
            '{last}' => $last,
            '{f}' => $first === '' ? '' : Str::substr($first, 0, 1),
            '{l}' => $last === '' ? '' : Str::substr($last, 0, 1),
        ];

        // A pattern that needs a surname can't be rendered for a one-name person.
        foreach (['{last}', '{l}'] as $needsLast) {
            if (Str::contains($pattern, $needsLast) && $last === '') {
                return null;
            }
        }

        $local = strtr($pattern, $replacements);

        // Drop any unknown tokens left behind, then tidy stray separators.
        $local = (string) preg_replace('/\{[a-z]+\}/', '', $local);
        $local = trim($local, '.-_');
        $local = (string) preg_replace('/[.\-_]{2,}/', '.', $local);

        return $local === '' ? null : $local;
    }

    /**
     * A person's name reduced to [firstSlug, lastSlug]. Returns null when there isn't even a first
     * name after slugging (punctuation or an emoji). The surname is the **last** token, so
     * "Jane van der Berg" keys on "berg" — imperfect, but deterministic and good enough for a guess.
     *
     * @return array{0: string, 1: string}|null
     */
    private function nameParts(string $name): ?array
    {
        $slug = Str::slug(Str::squish($name), '-');

        if ($slug === '') {
            return null;
        }

        $tokens = explode('-', $slug);
        $first = $tokens[0];

        if ($first === '') {
            return null;
        }

        $last = count($tokens) > 1 ? (string) end($tokens) : '';

        return [$first, $last];
    }

    private function normaliseDomain(string $domain): ?string
    {
        $domain = Str::lower(trim($domain));
        $domain = Str::replaceStart('www.', '', $domain);

        // Reject anything that isn't a plausible bare domain (must contain a dot, no spaces/at-sign).
        if ($domain === '' || ! Str::contains($domain, '.') || Str::contains($domain, [' ', '@', '/'])) {
            return null;
        }

        return $domain;
    }
}
