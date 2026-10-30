<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Services;

use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Support\Str;

/**
 * Renders the two text blocks the `scoring.qualitative_fit` prompt consumes: the team's ICP and the
 * lead under assessment. Both are built **only** from stored fields, and a field we don't hold is
 * stated as unknown rather than omitted — that is what keeps the model's explanation grounded
 * instead of plausible (the Phase 9 risk in mvp.md).
 *
 * The lead block is wrapped in {@see UntrustedContent}: its company facts came from provider APIs
 * and from AI extraction of the company's own web pages, so it is data, never instructions. The ICP
 * block is the team's own configuration and is rendered as trusted text, like the business profile
 * in other prompts.
 */
final class ScoringPayloadBuilder
{
    private const int MAX_LIST = 12;

    private const int MAX_VALUE = 200;

    /**
     * The ICP as the model should read it: weighted signals in descending weight, then exclusions.
     */
    public function icpBlock(Icp $icp): string
    {
        $icp->loadMissing('criteria');

        $lines = ['Name: '.$this->value($icp->name)];

        if ($icp->description !== null && trim($icp->description) !== '') {
            $lines[] = 'Description: '.$this->value($icp->description);
        }

        $signals = $icp->criteria
            ->reject(static fn (IcpCriterion $criterion): bool => $criterion->isExclusion())
            ->sortByDesc(static fn (IcpCriterion $criterion): int => $criterion->weight ?? 0)
            ->take(self::MAX_LIST)
            ->map(fn (IcpCriterion $criterion): string => sprintf(
                '- %s (weight %s): %s',
                $criterion->type->label(),
                $criterion->weight ?? 'unweighted',
                $this->value($criterion->value),
            ))
            ->all();

        $exclusions = $icp->criteria
            ->filter(static fn (IcpCriterion $criterion): bool => $criterion->isExclusion())
            ->take(self::MAX_LIST)
            ->map(fn (IcpCriterion $criterion): string => '- '.$this->value($criterion->value))
            ->all();

        $lines[] = '';
        $lines[] = 'Weighted signals (higher weight matters more):';
        $lines = [...$lines, ...($signals === [] ? ['- none given'] : $signals)];

        if ($exclusions !== []) {
            $lines[] = '';
            $lines[] = 'Exclusions (a lead matching any of these is not wanted):';
            $lines = [...$lines, ...$exclusions];
        }

        return implode("\n", $lines);
    }

    /**
     * The lead's stored facts, as untrusted data. Unknown fields are named as unknown so the model
     * cannot quietly fill them in.
     */
    public function leadBlock(Lead $lead): UntrustedContent
    {
        $lead->loadMissing('contacts');

        $titles = $lead->contacts
            ->map(static fn (LeadContact $contact): ?string => $contact->title)
            ->filter(static fn (?string $title): bool => $title !== null && trim($title) !== '')
            ->take(self::MAX_LIST)
            ->values()
            ->all();

        $lines = [
            'Company name: '.$this->value($lead->company_name),
            'Industry: '.$this->orUnknown($lead->industry),
            'Employee range: '.$this->orUnknown($lead->employee_range),
            'Location: '.$this->orUnknown($this->location($lead)),
            'Website: '.$this->orUnknown($lead->website),
            'Company status: '.$this->orUnknown($lead->company_status),
            'Company type: '.$this->orUnknown($lead->company_type),
            'Incorporated: '.$this->orUnknown($lead->incorporated_on?->toDateString()),
            'Technologies: '.$this->orUnknown($this->list($lead->technologies)),
            'Contact titles: '.$this->orUnknown($this->list($titles)),
            'Discovered via: '.$lead->source->label(),
            'Data last refreshed: '.$this->orUnknown($lead->enriched_at?->toDateString()),
        ];

        return UntrustedContent::from(implode("\n", $lines));
    }

    private function location(Lead $lead): ?string
    {
        $parts = array_values(array_filter(
            [$lead->locality, $lead->region, $lead->postal_code, $lead->country],
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        ));

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @param  array<array-key, mixed>|null  $items
     */
    private function list(?array $items): ?string
    {
        if ($items === null || $items === []) {
            return null;
        }

        $strings = array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) (is_scalar($item) ? $item : '')), $items),
            static fn (string $item): bool => $item !== '',
        ));

        return $strings === [] ? null : implode(', ', array_slice($strings, 0, self::MAX_LIST));
    }

    private function orUnknown(?string $value): string
    {
        return $value === null || trim($value) === '' ? 'unknown' : $this->value($value);
    }

    /**
     * Collapse newlines and clamp, so one long scraped field can't dominate the prompt or smuggle
     * in a fake section heading.
     */
    private function value(string $value): string
    {
        return Str::limit(Str::squish($value), self::MAX_VALUE, '');
    }
}
