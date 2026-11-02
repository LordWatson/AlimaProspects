<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Data\SignalQueryPlan;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns a {@see SignalWatch} and the {@see SignalDefinition}s it watches for (plus the team's active
 * ICP, if any) into the set of Serper `/news` queries a run executes. Deterministic — no AI.
 *
 * Since S7 the templates and the ICP keyword map come off the **definition rows**, not from
 * `config/signals.php` (ADR 0018): a signal type is data a team owns, and the config templates are
 * now seed data for the platform presets only.
 *
 * Since S8 a watch covers **places and regions, plural** (ADR 0019). The product is
 * `regions × locations × definitions × templates`, which is where the cost lives: six types of ten
 * templates over ten places in two regions is twelve hundred paid searches for one run. Three
 * things hold that:
 *
 * - `signals.max_locations_per_watch` and `max_regions_per_watch` bound the inputs
 *   (`SignalWatchData`).
 * - `signals.max_queries_per_run` bounds the product, here.
 * - The trim is **deterministic and reported**: {@see SignalQueryPlan} carries what was wanted as
 *   well as what will run, the run stores both, and the watch card shows the difference. A silent
 *   trim is a lie about coverage.
 *
 * **The definitions are passed in, not loaded.** That is what keeps this a pure function of its
 * inputs: the caller resolves them through a team-scoped query (which `docs/modules/signals.md`
 * requires of every parent reference, since the FKs are not team-qualified), and S9's "preview this
 * type before you save it" can call the same builder with a definition that has no row yet.
 *
 * An ICP Buying-signal criterion is **appended as an extra query** only when it maps to one of the
 * watched definitions — otherwise it is ignored and the reason logged (IDs/counts only, never
 * names). The mapping is first-match-wins over the definitions in the order given, which is how v1
 * behaved over the enum's declaration order; `SignalWatch::definitions()` orders by definition id
 * for exactly that reason.
 */
final class SignalQueryBuilder
{
    private const int MAX_QUERY = 200;

    /**
     * @param  list<SignalDefinition>  $definitions  The types to build for, in priority order.
     */
    public function build(SignalWatch $watch, array $definitions, ?Icp $icp = null): SignalQueryPlan
    {
        $regions = $this->resolveRegions($watch);
        $locations = $this->resolveLocations($watch, $regions);
        $tbs = (string) config('signals.recency', 'qdr:w');
        $num = max(1, min((int) config('signals.results_per_query', 20), 100));
        $ignoreDomains = $this->resolveIgnoreDomains($watch);

        /** @var array<string, SignalQuery> $queries keyed by region|eventKey|q to dedupe */
        $queries = [];

        // **Place, then region, then type, then template.** That order is also the trim order,
        // because the trim keeps the first N, so the nesting decides what a watch over budget
        // loses. Place outermost is what makes the loss survivable: coverage is dropped a whole
        // place at a time — a gap a member can see named on the card — and every region the member
        // picked keeps the places that did fit. Region outermost, which this was first written as,
        // spent the entire ceiling on the first region and left the second with **nothing**, which
        // is the one failure a member would not spot: they asked for two jurisdictions and silently
        // got one. Type above template means the first place searched covers every type.
        foreach ($locations as $location) {
            foreach ($regions as $region) {
                foreach ($definitions as $definition) {
                    foreach ($this->templatesFor($definition, $region) as $template) {
                        $q = $this->compose($template, $location);
                        $this->push($queries, $region, $definition->key, $q, $tbs, $num, $ignoreDomains);
                    }
                }
            }
        }

        if ($icp instanceof Icp) {
            $this->appendBuyingSignalQueries($watch, $icp, $definitions, $locations, $regions, $tbs, $num, $queries, $ignoreDomains);
        }

        return $this->plan(array_values($queries), $watch);
    }

    /**
     * Hold a run to `signals.max_queries_per_run`, and record what it cost in coverage.
     *
     * v1 could not exceed eight queries: three event types with three, three and two templates. S7
     * made that 25 types × 10 templates, and S8 multiplies again by places and regions — so this is
     * the one bound that scales with every dimension the feature has grown. Neither the per-watch
     * 15-minute throttle nor the per-member rate limit helps, because both bound how *often* a run
     * happens rather than how big one is.
     *
     * The trim is a **prefix** of the plan, which is only fair because `build()` orders the plan
     * place-major (see there). What it cannot promise is that every type is reached when the
     * ceiling is lower than one place's worth of queries — a team with 20 types of 10 templates and
     * a ceiling of 40 gets the first four types of its first place, and `wasTrimmed()` is how the
     * screen says so.
     *
     * @param  list<SignalQuery>  $queries
     */
    private function plan(array $queries, SignalWatch $watch): SignalQueryPlan
    {
        $requested = count($queries);
        $max = max(1, (int) config('signals.max_queries_per_run', 40));

        if ($requested <= $max) {
            return new SignalQueryPlan($queries, $requested);
        }

        Log::channel('signals')->warning('Signal run trimmed to the per-run query ceiling.', [
            'team_id' => (int) $watch->team_id,
            'signal_watch_id' => $watch->getKey(),
            'requested' => $requested,
            'ceiling' => $max,
        ]);

        return new SignalQueryPlan(array_slice($queries, 0, $max), $requested);
    }

    /**
     * The regions this watch searches, bounded and never empty.
     *
     * A stored region that is no longer **offered** is dropped rather than honoured: `offered` is a
     * compliance switch (ADR 0019 §3), so a region switched off because the business has not taken
     * a position on it must stop being searched — not keep running for the watches that already had
     * it. Falls back to the region matching the watch's own market, which is what the S8 backfill
     * gave every v1 watch anyway.
     *
     * @return list<SearchRegion>
     */
    private function resolveRegions(SignalWatch $watch): array
    {
        $max = max(1, (int) config('signals.max_regions_per_watch', 5));

        $regions = [];

        foreach ($watch->regions ?? [] as $value) {
            $region = SearchRegion::tryFrom($value);

            if ($region === null || ! $region->isOffered()) {
                continue;
            }

            if (! in_array($region, $regions, strict: true)) {
                $regions[] = $region;
            }
        }

        if ($regions === []) {
            $regions[] = SearchRegion::forMarket($watch->market);
        }

        return array_slice($regions, 0, $max);
    }

    /**
     * The places this watch covers, bounded, de-duplicated and never empty.
     *
     * Falls back to the regions' own default places when the watch names none — a watch that
     * searched nowhere would complete successfully having found nothing, which is the most
     * confusing failure this feature could have.
     *
     * @param  list<SearchRegion>  $regions
     * @return list<string>
     */
    private function resolveLocations(SignalWatch $watch, array $regions): array
    {
        $max = max(1, (int) config('signals.max_locations_per_watch', 10));

        $locations = [];

        foreach ($watch->locations ?? [] as $place) {
            $clean = Str::squish($place);

            if ($clean !== '' && ! in_array($clean, $locations, strict: true)) {
                $locations[] = $clean;
            }
        }

        if ($locations === []) {
            foreach ($regions as $region) {
                foreach ($region->defaultLocations() as $place) {
                    if (! in_array($place, $locations, strict: true)) {
                        $locations[] = $place;
                    }
                }
            }
        }

        return array_slice($locations, 0, $max);
    }

    /**
     * The templates to use for a definition in a region.
     *
     * A region whose `hl` is not English uses the definition's translated templates **where it has
     * them** and the English ones where it does not (ADR 0019 §5). The fallback is the point:
     * adding an Arabic-language region must never silently empty a watch, and a definition nobody
     * has translated is the normal case rather than an error.
     *
     * @return list<string>
     */
    private function templatesFor(SignalDefinition $definition, SearchRegion $region): array
    {
        $hl = $region->hl();

        if ($hl !== 'en') {
            $translated = $definition->templatesForLanguage($hl);

            if ($translated !== []) {
                return $translated;
            }
        }

        return $definition->query_templates;
    }

    /**
     * Append an extra query per ICP buying signal that maps to one of the watched definitions.
     *
     * One per (region × location), like the templates — a buying signal is a search like any other
     * and has to be asked in each place the watch covers, or it would only ever find things in
     * whichever place happened to come first.
     *
     * @param  list<SignalDefinition>  $definitions
     * @param  list<string>  $locations
     * @param  list<SearchRegion>  $regions
     * @param  array<string, SignalQuery>  $queries
     * @param  list<string>  $ignoreDomains
     */
    private function appendBuyingSignalQueries(
        SignalWatch $watch,
        Icp $icp,
        array $definitions,
        array $locations,
        array $regions,
        string $tbs,
        int $num,
        array &$queries,
        array $ignoreDomains = [],
    ): void {
        // Mirrors `build()`'s place-major nesting, for the same reason: these are appended to the
        // same plan and trimmed by the same prefix.
        foreach ($this->buyingSignals($icp) as $signal) {
            $definition = $this->matchDefinition($signal, $definitions);

            if (! $definition instanceof SignalDefinition) {
                Log::channel('signals')->debug('ICP buying signal ignored for query building: no watched signal type matched its keywords.', [
                    'signal_watch_id' => $watch->getKey(),
                ]);

                continue;
            }

            foreach ($locations as $location) {
                foreach ($regions as $region) {
                    $q = $this->compose(trim($location.' '.$signal), $location);
                    $this->push($queries, $region, $definition->key, $q, $tbs, $num, $ignoreDomains);
                }
            }
        }
    }

    /**
     * The non-empty Buying-signal criterion values of an ICP.
     *
     * @return list<string>
     */
    private function buyingSignals(Icp $icp): array
    {
        $icp->loadMissing('criteria');

        return array_values($icp->criteria
            ->filter(static fn (IcpCriterion $c): bool => $c->type === CriterionType::BuyingSignal)
            ->map(static fn (IcpCriterion $c): string => trim($c->value))
            ->filter(static fn (string $v): bool => $v !== '')
            ->all());
    }

    /**
     * Map a buying-signal phrase onto one of the watched definitions by keyword, or null when none
     * matches.
     *
     * Substring, case-insensitive, first definition wins — kept exactly as v1's behaviour (`rais`
     * matches "raises/raised", `exit` matches "exits") because it is deterministic and testable.
     * What changed in S7 is where the keywords live: on the definition, so a team that writes their
     * own type can say which of their buying signals it answers. Word-boundary matching remains the
     * post-v1 refinement `docs/modules/signals.md` records.
     *
     * @param  list<SignalDefinition>  $definitions
     */
    private function matchDefinition(string $text, array $definitions): ?SignalDefinition
    {
        $haystack = Str::lower($text);

        foreach ($definitions as $definition) {
            foreach ($definition->match_keywords ?? [] as $keyword) {
                $needle = Str::lower(trim($keyword));

                if ($needle !== '' && Str::contains($haystack, $needle)) {
                    return $definition;
                }
            }
        }

        return null;
    }

    /**
     * Substitute `{location}` and tidy whitespace, clamped to the query length.
     */
    private function compose(string $template, string $location): string
    {
        $q = str_replace('{location}', $location, $template);
        $q = trim((string) preg_replace('/\s+/', ' ', $q));

        return Str::limit($q, self::MAX_QUERY, '');
    }

    /**
     * Add a query, de-duplicating identical (region + event key + text) triples.
     *
     * The region is part of the key because the same text in two regions is two different searches:
     * "London company acquisition" asked with `gl=gb` and with `gl=ae` returns different news,
     * which is the whole reason a watch can name more than one.
     *
     * @param  array<string, SignalQuery>  $queries
     * @param  list<string>  $ignoreDomains
     */
    private function push(array &$queries, SearchRegion $region, string $eventKey, string $q, string $tbs, int $num, array $ignoreDomains = []): void
    {
        if ($q === '') {
            return;
        }

        $key = $region->value.'|'.$eventKey.'|'.$q;

        if (isset($queries[$key])) {
            return;
        }

        $queries[$key] = new SignalQuery(
            eventKey: $eventKey,
            q: $q,
            region: $region,
            gl: $region->gl(),
            hl: $region->hl(),
            tbs: $tbs,
            num: $num,
            ignoreDomains: $ignoreDomains,
        );
    }

    /**
     * The watch's own domains to exclude (S6), normalised to bare lower-case hosts.
     *
     * A team types what they see — "www.Competitor.ae", or a whole URL pasted out of the address
     * bar — so a host is extracted where there is one and `www.` is stripped, which is the form the
     * client compares against. Blank entries are dropped and duplicates collapsed, so a sloppy
     * list still works.
     *
     * @return list<string>
     */
    private function resolveIgnoreDomains(SignalWatch $watch): array
    {
        $domains = [];

        foreach ($watch->ignore_domains ?? [] as $entry) {
            $value = Str::lower(trim($entry));

            if ($value === '') {
                continue;
            }

            if (Str::contains($value, '/')) {
                $host = parse_url(Str::startsWith($value, ['http://', 'https://']) ? $value : 'https://'.$value, PHP_URL_HOST);
                $value = is_string($host) ? $host : $value;
            }

            // A team that types a Unicode domain would otherwise never match the punycode host
            // the provider returns.
            if (! mb_check_encoding($value, 'ASCII') && function_exists('idn_to_ascii')) {
                $ascii = idn_to_ascii($value);
                $value = is_string($ascii) && $ascii !== '' ? $ascii : $value;
            }

            // The trailing dot matters: `rival.ae.` is a valid absolute FQDN resolving to the same
            // place, but a different string — and the comparison downstream is a string one.
            $value = Str::replaceStart('www.', '', rtrim($value, '.'));

            if ($value !== '' && ! in_array($value, $domains, strict: true)) {
                $domains[] = $value;
            }
        }

        return $domains;
    }
}
