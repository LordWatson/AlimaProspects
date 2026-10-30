<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Services;

use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Data\CriterionScore;
use App\Modules\Scoring\Data\DeterministicScore;
use App\Modules\Scoring\Enums\CriterionMatch;
use Illuminate\Support\Str;

/**
 * The rule-based half of the hybrid score: walks an {@see Icp}'s criteria against a {@see Lead}'s
 * own stored fields and returns a normalised 0–100 fit score with a per-criterion breakdown. No AI,
 * no network — given the same (lead, ICP) it always returns the same answer, which is what makes
 * the explanation auditable.
 *
 * Three rules shape the arithmetic:
 *
 * 1. **Weights are normalised, not summed.** The score is `matchedWeight / assessedWeight`, so an
 *    ICP with three criteria and one with thirty sit on the same 0–100 scale, and nobody can inflate
 *    a lead by adding more criteria.
 * 2. **Missing data is not a failure.** When the lead has nothing on a criterion's dimension the row
 *    is {@see CriterionMatch::Unknown} and its weight leaves *both* sides of the ratio — otherwise
 *    the score would measure enrichment coverage rather than fit. Buying signals are always Unknown
 *    here; only the AI half can judge them.
 * 3. **An exclusion is absolute.** A matched exclusion returns 0 immediately — no partial credit,
 *    and the caller skips the AI call entirely.
 */
final class DeterministicScorer
{
    /**
     * Criterion values shorter than this are matched on a word boundary rather than as a substring,
     * so "IT" doesn't match "security" and "AE" doesn't match "Wales".
     */
    private const int SHORT_VALUE = 4;

    public function score(Lead $lead, Icp $icp): DeterministicScore
    {
        // Explicit eager loads: preventLazyLoading is on outside production.
        $icp->loadMissing('criteria');
        $lead->loadMissing('contacts');

        $exclusionHaystack = $this->exclusionHaystack($lead);

        /** @var list<CriterionScore> $rows */
        $rows = [];
        $matchedWeight = 0;
        $assessedWeight = 0;

        foreach ($icp->criteria as $criterion) {
            if ($criterion->isExclusion()) {
                $hit = $this->matchAny($criterion->value, $exclusionHaystack);

                if ($hit !== null) {
                    // A disqualifier: stop here with the exclusion as the only breakdown row, so the
                    // UI can say exactly what ruled the lead out.
                    return DeterministicScore::excludedBy($criterion->value, [
                        new CriterionScore(
                            type: CriterionType::Exclusion,
                            value: $criterion->value,
                            weight: 0,
                            match: CriterionMatch::Matched,
                            evidence: $hit,
                        ),
                    ]);
                }

                continue; // an unmatched exclusion contributes nothing either way
            }

            $weight = $this->weightOf($criterion);
            $row = $this->assess($criterion, $lead, $weight);
            $rows[] = $row;

            if ($row->match->isAssessed()) {
                $assessedWeight += $weight;

                if ($row->match === CriterionMatch::Matched) {
                    $matchedWeight += $weight;
                }
            }
        }

        $score = $assessedWeight > 0 ? (int) round($matchedWeight / $assessedWeight * 100) : 0;

        return new DeterministicScore(
            score: $score,
            breakdown: $this->withContributions($rows, $assessedWeight),
            matchedWeight: $matchedWeight,
            assessedWeight: $assessedWeight,
        );
    }

    /**
     * Judge one positive signal against the lead's data for that dimension.
     */
    private function assess(IcpCriterion $criterion, Lead $lead, int $weight): CriterionScore
    {
        // A buying signal (funding, hiring, expansion…) isn't in any column we hold, so the rules
        // must abstain and let the AI half speak to it.
        if ($criterion->type === CriterionType::BuyingSignal) {
            return new CriterionScore(
                type: $criterion->type,
                value: $criterion->value,
                weight: $weight,
                match: CriterionMatch::Unknown,
                evidence: 'Assessed by the AI review only.',
            );
        }

        if ($criterion->type === CriterionType::CompanySize) {
            return $this->assessCompanySize($criterion, $lead, $weight);
        }

        $haystack = $this->haystackFor($criterion->type, $lead);

        if ($haystack === []) {
            return new CriterionScore(
                type: $criterion->type,
                value: $criterion->value,
                weight: $weight,
                match: CriterionMatch::Unknown,
                evidence: $this->missingDataNote($criterion->type),
            );
        }

        $hit = $this->matchAny($criterion->value, $haystack);

        return new CriterionScore(
            type: $criterion->type,
            value: $criterion->value,
            weight: $weight,
            match: $hit !== null ? CriterionMatch::Matched : CriterionMatch::NotMatched,
            evidence: $hit ?? implode(', ', array_slice($haystack, 0, 3)),
        );
    }

    /**
     * Company size compares ranges, not words: "11–50 employees" matches a lead on "25-60" because
     * the bands overlap. When either side carries no numbers at all, fall back to text matching.
     */
    private function assessCompanySize(IcpCriterion $criterion, Lead $lead, int $weight): CriterionScore
    {
        $range = trim((string) $lead->employee_range);

        if ($range === '') {
            return new CriterionScore(
                type: $criterion->type,
                value: $criterion->value,
                weight: $weight,
                match: CriterionMatch::Unknown,
                evidence: $this->missingDataNote($criterion->type),
            );
        }

        $wanted = $this->bounds($criterion->value);
        $actual = $this->bounds($range);

        $matched = $wanted !== null && $actual !== null
            ? $wanted[0] <= $actual[1] && $actual[0] <= $wanted[1]
            : $this->matchAny($criterion->value, [$range]) !== null;

        return new CriterionScore(
            type: $criterion->type,
            value: $criterion->value,
            weight: $weight,
            match: $matched ? CriterionMatch::Matched : CriterionMatch::NotMatched,
            evidence: $range,
        );
    }

    /**
     * The lead values a criterion type is judged against. An empty list means "no data" (Unknown).
     *
     * @return list<string>
     */
    private function haystackFor(CriterionType $type, Lead $lead): array
    {
        $values = match ($type) {
            CriterionType::Industry => [$lead->industry],
            CriterionType::Geography => [
                $lead->locality,
                $lead->region,
                $lead->postal_code,
                ...$this->countryAliases($lead->country),
            ],
            CriterionType::JobTitle, CriterionType::Seniority => $this->contactTitles($lead),
            CriterionType::Technographic => $lead->technologies ?? [],
            // Company size is handled by assessCompanySize(), exclusions by the caller, and buying
            // signals abstain. Listed so the match stays exhaustive.
            CriterionType::CompanySize, CriterionType::BuyingSignal, CriterionType::Exclusion => [],
        };

        return $this->clean($values);
    }

    /**
     * Everything an exclusion is tested against: who the company is and where it lives on the web.
     *
     * @return list<string>
     */
    private function exclusionHaystack(Lead $lead): array
    {
        $emailDomains = $lead->contacts
            ->map(static fn (LeadContact $contact): ?string => $contact->email === null
                ? null
                : Str::after($contact->email, '@'))
            ->all();

        return $this->clean([
            $lead->company_name,
            $lead->company_number,
            $lead->website,
            $lead->domain(),
            $lead->industry,
            $lead->locality,
            $lead->region,
            ...$this->countryAliases($lead->country),
            ...$emailDomains,
        ]);
    }

    /**
     * The titles stated on the lead's contacts (enrichment may have found none).
     *
     * @return list<string>
     */
    private function contactTitles(Lead $lead): array
    {
        return $this->clean(
            $lead->contacts
                ->map(static fn (LeadContact $contact): ?string => $contact->title)
                ->all(),
        );
    }

    /**
     * A country code plus its common long forms, so an ICP written as "UAE" still matches a lead
     * stored as "AE" (ADR 0007: the MVP markets are the UK and the UAE).
     *
     * @return list<string>
     */
    private function countryAliases(?string $country): array
    {
        $value = Str::lower(trim((string) $country));

        if ($value === '') {
            return [];
        }

        /** @var array<array-key, mixed> $aliases */
        $aliases = (array) config('scoring.country_aliases', []);

        foreach ($aliases as $group) {
            $names = array_map(
                static fn (mixed $item): string => Str::lower(trim((string) $item)),
                (array) $group,
            );

            if (in_array($value, $names, strict: true)) {
                return array_values($names);
            }
        }

        return [$value];
    }

    /**
     * Does any alternative in the criterion value appear in the haystack? Returns the lead value
     * that matched (the evidence), or null. A comma/slash-separated criterion reads as "any of".
     *
     * @param  list<string>  $haystack
     */
    private function matchAny(string $criterionValue, array $haystack): ?string
    {
        foreach ($this->alternatives($criterionValue) as $needle) {
            foreach ($haystack as $candidate) {
                if ($this->matches($needle, $candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Case- and punctuation-insensitive containment, either direction ("SaaS" matches "B2B SaaS",
     * and "London" matches a criterion of "Greater London"). Short values must land on a word
     * boundary so two-letter codes don't match inside unrelated words.
     */
    private function matches(string $needle, string $candidate): bool
    {
        $needle = $this->normalise($needle);
        $candidate = $this->normalise($candidate);

        if ($needle === '' || $candidate === '') {
            return false;
        }

        if (mb_strlen($needle) < self::SHORT_VALUE) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';

            return preg_match($pattern, $candidate) === 1;
        }

        return str_contains($candidate, $needle) || str_contains($needle, $candidate);
    }

    /**
     * Split a criterion value into its alternatives on Latin and Arabic list separators.
     *
     * @return list<string>
     */
    private function alternatives(string $value): array
    {
        $parts = preg_split('/[,;\/|\x{060C}\x{061B}]+/u', $value);

        return $this->clean($parts === false ? [$value] : $parts);
    }

    /**
     * Lower-case, strip punctuation to spaces and squish, so "B2B-SaaS." and "b2b saas" meet.
     */
    private function normalise(string $value): string
    {
        $stripped = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return Str::squish(Str::lower($stripped ?? $value));
    }

    /**
     * The low/high employee bounds stated in a size label, or null when it names no numbers.
     * "11-50" gives [11, 50]; "50+" gives [50, PHP_INT_MAX]; "under 10" gives [0, 10].
     *
     * @return array{int, int}|null
     */
    private function bounds(string $value): ?array
    {
        if (preg_match_all('/\d+/', $value, $matches) === false) {
            return null;
        }

        $numbers = array_map('intval', $matches[0]);

        if ($numbers === []) {
            return null;
        }

        if (count($numbers) >= 2) {
            return [min($numbers), max($numbers)];
        }

        $single = $numbers[0];

        // An open-ended band: "50+", "over 50", "more than 50" run upward; "under 10", "fewer than
        // 10", "up to 10" run downward. A bare number is just itself.
        if (preg_match('/\+|\bover\b|\bmore\b|\babove\b|\bplus\b/i', $value) === 1) {
            return [$single, PHP_INT_MAX];
        }

        if (preg_match('/\bunder\b|\bbelow\b|\bfewer\b|\bless\b|\bup to\b|\bmax\b/i', $value) === 1) {
            return [0, $single];
        }

        return [$single, $single];
    }

    /**
     * The criterion's weight: its own 1–100, or the configured default when the team left it blank.
     */
    private function weightOf(IcpCriterion $criterion): int
    {
        $weight = $criterion->weight ?? (int) config('scoring.default_signal_weight', 50);

        return max(1, min(100, $weight));
    }

    /**
     * Spread the deterministic score across its matched rows, so the breakdown's contributions add
     * up to the score the user sees.
     *
     * @param  list<CriterionScore>  $rows
     * @return list<CriterionScore>
     */
    private function withContributions(array $rows, int $assessedWeight): array
    {
        if ($assessedWeight <= 0) {
            return $rows;
        }

        return array_map(
            static fn (CriterionScore $row): CriterionScore => $row->withContribution(
                $row->match === CriterionMatch::Matched
                    ? (int) round($row->weight / $assessedWeight * 100)
                    : 0,
            ),
            $rows,
        );
    }

    /**
     * Why a criterion couldn't be judged — shown in the breakdown so "No data" is never a mystery.
     */
    private function missingDataNote(CriterionType $type): string
    {
        return match ($type) {
            CriterionType::Industry => 'No industry on this lead yet.',
            CriterionType::CompanySize => 'No company size on this lead yet.',
            CriterionType::Geography => 'No location on this lead yet.',
            CriterionType::JobTitle, CriterionType::Seniority => 'No contact titles on this lead yet.',
            CriterionType::Technographic => 'No technologies detected on this lead yet.',
            default => 'Not enough data on this lead yet.',
        };
    }

    /**
     * Trim, drop empties and re-index a mixed list of candidate strings.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function clean(array $values): array
    {
        $out = [];

        foreach ($values as $value) {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }

            $trimmed = trim((string) $value);

            if ($trimmed !== '' && ! in_array($trimmed, $out, strict: true)) {
                $out[] = $trimmed;
            }
        }

        return $out;
    }
}
