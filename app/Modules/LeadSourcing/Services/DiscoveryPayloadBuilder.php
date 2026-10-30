<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Scoring\Services\ScoringPayloadBuilder;
use Illuminate\Support\Str;

/**
 * Renders the text blocks the `lead_sourcing.shape_query` prompt consumes: the team's own business,
 * its ICP, and a brief on what the chosen provider's search actually accepts.
 *
 * Both the profile and the ICP are the team's own typed configuration, so they go in as trusted text
 * — except `customer_examples` and the ICP's free-text criteria values, which a member typed and
 * which therefore get wrapped: they are the one place a prompt-injection attempt could be parked in
 * this flow. Every block states a missing field as unknown rather than omitting it, so the model
 * cannot quietly invent one (the same rule {@see ScoringPayloadBuilder}
 * follows).
 */
final class DiscoveryPayloadBuilder
{
    private const int MAX_LIST = 12;

    private const int MAX_VALUE = 200;

    /**
     * The team's own business — the seller. The prompt's whole job is to invert this into buyers, so
     * the block leads with what they sell and who already buys, which are the two strongest clues.
     */
    public function businessBlock(?BusinessProfile $profile): UntrustedContent
    {
        if (! $profile instanceof BusinessProfile) {
            return new UntrustedContent(
                'unknown — this team has not completed their business profile, so there is nothing to '
                .'infer their customers from. Return an empty `terms`.'
            );
        }

        $lines = [
            'Our company: '.$this->orUnknown($profile->company_name),
            'What we sell: '.$this->orUnknown($this->list($profile->products_services)),
            'Why customers choose us: '.$this->orUnknown($profile->value_proposition),
            'Our own industry (NEVER search for this — it is our competition): '
                .$this->orUnknown($profile->industry),
            'Where we operate: '.$this->orUnknown($this->list($profile->locations)),
            'Regions we serve: '.$this->orUnknown($this->list($profile->regions_served)),
            'Customers we already have: '.$this->orUnknown($this->list($profile->customer_examples)),
            'Our website: '.$this->orUnknown($profile->website),
        ];

        return new UntrustedContent(implode("\n", $lines));
    }

    /**
     * The ICP as the model should read it: weighted signals in descending weight, then exclusions.
     *
     * The caveat on the industry signals is deliberate. A team's ICP can have been seeded from an
     * older background check that conflated the two sides, so an industry signal here is not reliably
     * a *customer* industry. The model is told to weigh it against the profile rather than trust it —
     * which is the behaviour chosen for an ambiguous ICP: the profile is the source of truth.
     */
    public function icpBlock(?Icp $icp): UntrustedContent
    {
        if (! $icp instanceof Icp) {
            return new UntrustedContent(
                'none — this team has no ideal-customer definition yet. Infer their customers from the '
                .'business profile alone.'
            );
        }

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

        $lines[] = '';
        $lines[] = 'Treat these signals as a guide, not as fact: some were generated automatically and '
            .'may wrongly name our OWN trade rather than our customers. Where a signal contradicts the '
            .'business profile, follow the profile.';

        if ($exclusions !== []) {
            $lines[] = '';
            $lines[] = 'Exclusions (never return a company matching any of these):';
            $lines = [...$lines, ...$exclusions];
        }

        return new UntrustedContent(implode("\n", $lines));
    }

    /**
     * What the chosen provider's search will and won't do, so the model phrases the query for the
     * endpoint that actually receives it rather than for a generic search box.
     */
    public function providerBrief(LeadSource $source): string
    {
        return match ($source) {
            LeadSource::CompaniesHouse => implode("\n", [
                'This is the UK Companies House register. It matches against REGISTERED COMPANY NAMES '
                    .'only — not activities, descriptions or websites.',
                'So give a short trade word or two that tends to appear IN a company name '
                    .'("joinery", "dental practice", "letting agents"). A sentence, a question or a '
                    .'segment description returns nothing.',
                'It cannot filter by location, so the location is ignored for this provider.',
            ]),
            LeadSource::GooglePlaces => implode("\n", [
                'This is the Google Places text search. It finds businesses with a physical presence by '
                    .'category and place, and the location is appended for you as "<terms> in <place>".',
                'So give the category a customer business would be listed under ("boutique hotel", '
                    .'"dental clinic", "car dealership"). Strongest for local and consumer-facing trades; '
                    .'weak for businesses with no premises.',
                'It does NOT support negative terms — exclusions are applied by dropping results '
                    .'afterwards, so keep the query itself narrow.',
            ]),
            LeadSource::WebSearch => implode("\n", [
                'This is a web search (Google via Serper). It reads whole pages, so natural phrasing '
                    .'works and you may use quoted phrases for precision.',
                'It DOES support negative terms: anything in `exclude_terms` is appended as -"term", so '
                    .'name the trades and competitor names that would otherwise crowd the results.',
                'Aim at pages that list or describe customer companies. Directory, social and '
                    .'aggregator domains are discarded automatically, so do not target them.',
            ]),
            // Import, Manual and Signal label where a lead came from; none of them is a provider that
            // can be searched, and StartDiscoveryRequest only accepts the runnable sources. Reached
            // only by a programming error, so say nothing useful rather than invent a brief.
            default => 'This source cannot be searched.',
        };
    }

    /**
     * @param  list<string>|null  $values
     */
    private function list(?array $values): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        return implode(', ', array_slice($values, 0, self::MAX_LIST));
    }

    private function orUnknown(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'unknown';
        }

        return $this->value($value);
    }

    private function value(string $value): string
    {
        return Str::limit(trim($value), self::MAX_VALUE, '');
    }
}
