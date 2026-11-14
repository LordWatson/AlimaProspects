<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Onboarding\Support\SellerBrief;
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

        return new UntrustedContent((string) SellerBrief::for($profile));
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
                'This is the UK Companies House register. It can be searched two ways.',
                'By SIC code (preferred): give up to four five-digit UK SIC 2007 codes for the trades the '
                    .'CUSTOMERS are in, in `sic_codes`. The register is then filtered to active companies '
                    .'with those codes in the location. Only give a code you are sure of; leave the list '
                    .'empty rather than guess.',
                'By name (the fallback): `name_terms` matches against REGISTERED COMPANY NAMES only — not '
                    .'activities, descriptions or websites. So give a short trade word or two that tends '
                    .'to appear IN a company name ("joinery", "dental practice", "letting agents"). A '
                    .'sentence, a question or a segment description returns nothing.',
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
                'This is a web search (Google via Serper). Each result becomes one candidate company, so '
                    .'a query must surface the OWN WEBSITES OF CUSTOMER COMPANIES — not articles, lists, '
                    .'directories or "contact us" pages.',
                'Give up to three queries in `web_queries`, each a different angle and each a few plain '
                    .'keywords: (1) the strongest customer trade; (2) a second customer trade, or the trade '
                    .'plus the situation that makes it buy; (3) companies like the named existing '
                    .'customers. No quotation marks, no OR chains, no "contact us" or "family run" filler: '
                    .'a long query returns pages about the topic rather than companies in it.',
                'Anything in `exclude_terms` is removed from the results, so name the trades and '
                    .'competitor names that would otherwise crowd them.',
            ]),
            LeadSource::Ai => implode("\n", [
                'This is not a search engine: `ai_brief` is handed to a model that picks out the '
                    .'matching companies.',
                'So describe the KIND of company to name, in the words a person would use — '
                    .'"independent boutique hotels", "mid-size private dental groups" — including the '
                    .'size and situation that make one a good customer. No search operators.',
            ]),
            // Import, Manual and Signal label where a lead came from; none of them is a provider that
            // can be searched, and StartDiscoveryRequest only accepts the runnable sources. Reached
            // only by a programming error, so say nothing useful rather than invent a brief.
            default => 'This source cannot be searched.',
        };
    }

    private function value(string $value): string
    {
        return Str::limit(trim($value), self::MAX_VALUE, '');
    }
}
