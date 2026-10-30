<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;

/**
 * Drops discovered candidates that match the query's negative terms, before anything is persisted.
 *
 * Negative search operators only exist on the web-search provider; Companies House matches company
 * names and Google Places matches categories, and neither will take an exclusion. So the guarantee
 * has to be enforced here, once, over every provider's results — otherwise "never show me
 * competitors" would hold for one source out of three.
 *
 * It runs **before** persistence on purpose. The scorer already grades an excluded lead D, but by
 * then the company is in the team's workspace: it has been enriched (paid calls), it counts against
 * the lead allowance, and a member has to look at it. Filtering a provider's response is the cheapest
 * point at which a competitor can be refused.
 *
 * Matching is case-insensitive and deliberately cheap — the alternative is a model call per lead to
 * decide whether "Studio Eight Design" is a design studio — but it is not a plain substring test,
 * because that rejected real customers: an exclusion of `design` threw away "Designer Outlet Ltd",
 * and `marketing` threw away "Marketplace Holdings". So the company **name** is matched on word
 * boundaries, and only the **domain** keeps a substring test, where there are no word boundaries to
 * respect and a trade word is usually welded to the rest of the host. The 3-character floor and the
 * cap on how many exclusions a query may carry ({@see DiscoveryQuery}) are the other half of keeping
 * a cheap rule from eating the result set.
 */
final class LeadCandidateFilter
{
    /**
     * Below this length a term is matched against the name only. A short word buried in a domain
     * ("art" in `smartgroup.ae`) is far more often a coincidence than a trade.
     */
    private const int MIN_DOMAIN_TERM_LENGTH = 5;

    /**
     * @param  list<SourcedLead>  $leads
     * @return array{0: list<SourcedLead>, 1: int} the kept leads, and how many were rejected
     */
    public function apply(DiscoveryQuery $query, array $leads): array
    {
        if ($query->excludeTerms === []) {
            return [$leads, 0];
        }

        $needles = array_map(static fn (string $term): string => mb_strtolower($term), $query->excludeTerms);

        $kept = [];
        $rejected = 0;

        foreach ($leads as $lead) {
            if ($this->isExcluded($lead, $needles)) {
                $rejected++;

                continue;
            }

            $kept[] = $lead;
        }

        return [$kept, $rejected];
    }

    /**
     * Whether this candidate matches any negative term, by company name or by domain.
     *
     * @param  list<string>  $needles  already lower-cased
     */
    private function isExcluded(SourcedLead $lead, array $needles): bool
    {
        $name = mb_strtolower($lead->companyName);
        $domain = $lead->domain();
        $domain = $domain === null ? null : mb_strtolower($domain);

        foreach ($needles as $needle) {
            if ($this->matchesName($name, $needle)) {
                return true;
            }

            // A domain has no word boundaries to respect — "design" should catch
            // `brightdesign.co.uk`, because a competitor's trade is usually welded into its host
            // even when its trading name hides it. Short terms are held back from this test, where
            // an accidental substring is the likelier explanation.
            if ($domain !== null
                && mb_strlen($needle) >= self::MIN_DOMAIN_TERM_LENGTH
                && str_contains($domain, $needle)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the term appears in the company name as a whole word (or whole phrase).
     *
     * Word-bounded so that excluding `design` drops "Apex Design" but keeps "Designer Outlet", and
     * excluding `marketing` keeps "Marketplace Holdings". A substring test here was rejecting real
     * customers whose names merely began with the same letters as the team's trade.
     *
     * @param  string  $name  already lower-cased
     * @param  string  $needle  already lower-cased
     */
    private function matchesName(string $name, string $needle): bool
    {
        // `\b` is unreliable either side of a non-ASCII letter, so the boundary is spelled out as
        // "start/end of string, or a character that is not a letter or digit" and the pattern runs
        // in Unicode mode. An Arabic trade name is a first-class case here, not an edge one.
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';

        return preg_match($pattern, $name) === 1;
    }
}
