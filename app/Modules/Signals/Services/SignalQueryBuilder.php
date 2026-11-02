<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Data\SignalQueryPlan;
use App\Modules\Signals\Data\SignalQuerySpec;
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
 * **Since S9 the "where" is a {@see SignalQuerySpec} rather than a watch** — the places, the
 * regions and the skip list, resolved once, with no model behind them. That is what let the preview
 * exist: it previews a definition somebody is still typing, for a team that may not have created a
 * watch yet, and a builder that needs a saved row is the reason previews are hard. {@see build()}
 * is the watch-shaped convenience over {@see buildFromSpec()}, so there is still exactly one code
 * path.
 *
 * **The definitions are passed in, not loaded.** That is what keeps this a pure function of its
 * inputs: the caller resolves them through a team-scoped query (which `docs/modules/signals.md`
 * requires of every parent reference, since the FKs are not team-qualified), and the preview calls
 * it with a definition that has no row at all.
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
     * The `eventKey` an entity query carries.
     *
     * A company is searched by name with no event word in it, so the query is not *about* one type
     * — the classifier decides which of the watch's types the article turns out to be, as it always
     * has. The key is only the dedupe map's key here, and a sentinel says that plainly rather than
     * naming an arbitrary definition and implying a claim the query does not make.
     */
    private const string ENTITY_KEY = '*account';

    /**
     * The queries one run of a watch would ask for.
     *
     * @param  list<SignalDefinition>  $definitions  The types to build for, in priority order.
     */
    public function build(SignalWatch $watch, array $definitions, ?Icp $icp = null): SignalQueryPlan
    {
        return $this->buildFromSpec(SignalQuerySpec::forWatch($watch), $definitions, $icp);
    }

    /**
     * The queries any "where" would ask for — a watch's, or a draft definition's preview.
     *
     * @param  list<SignalDefinition>  $definitions  The types to build for, in priority order.
     * @param  int|null  $resultsPerQuery  Overridden by the preview, which asks for fewer.
     */
    public function buildFromSpec(
        SignalQuerySpec $spec,
        array $definitions,
        ?Icp $icp = null,
        ?int $resultsPerQuery = null,
        ?int $maxQueries = null,
    ): SignalQueryPlan {
        $regions = $spec->regions;
        $locations = $spec->locations;
        $tbs = (string) config('signals.recency', 'qdr:w');
        $num = max(1, min($resultsPerQuery ?? (int) config('signals.results_per_query', 20), 100));
        $ignoreDomains = $spec->ignoreDomains;

        if ($spec->isEntityScoped()) {
            return $this->buildEntityQueries($spec, $definitions, $tbs, $num, $maxQueries);
        }

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
            $this->appendBuyingSignalQueries($spec, $icp, $definitions, $locations, $regions, $tbs, $num, $queries, $ignoreDomains);
        }

        return $this->plan(array_values($queries), $spec, $maxQueries);
    }

    /**
     * The queries an **entity-scoped** watch asks: one per account, by name (S10).
     *
     * **A bare name, not a quoted phrase, and that is a spike finding rather than a preference.**
     * The plan assumed a company name was the one case where exact-phrase quoting is right, and
     * asked for it to be checked. It was, over six companies that were in the news that week, and
     * **every quoted query returned zero** — twelve of twelve, including single-word names where
     * quoting cannot even change the meaning. Serper's `/news` endpoint with a recency window
     * appears not to serve quoted queries at all, which is the same wall the S0 spike hit from the
     * other side. The table is in `docs/modules/signals.md`.
     *
     * **A company is searched by name alone, with no keyword appended**, which is the second thing
     * the spike settled. The bare name was 70–100% on topic by itself, and adding a keyword cost
     * genuine results rather than sharpening them: one subject went from nine results to **none**.
     * So the search finds everything about the account and `signals.extract_event` decides whether
     * any of it is an event of a watched type — which is what the classifier is for, and it already
     * receives the watch's definitions.
     *
     * **A person is not searched by name alone**, because "Jane Smith" returns every Jane Smith
     * alive. Each person entity gets one query per definition, qualified by that definition's first
     * match keyword — the stems a team already wrote for ICP matching, which are exactly the words
     * that appear in a sentence about this kind of event.
     *
     * @param  list<SignalDefinition>  $definitions
     */
    private function buildEntityQueries(
        SignalQuerySpec $spec,
        array $definitions,
        string $tbs,
        int $num,
        ?int $maxQueries,
    ): SignalQueryPlan {
        /** @var array<string, SignalQuery> $queries */
        $queries = [];

        // **Entity, then region, then (for a person) type.** Entity outermost, because the trim
        // keeps the first N and an account half-searched is worse than an account not searched this
        // run — the cursor brings it round tomorrow either way.
        foreach ($spec->entities as $entity) {
            foreach ($spec->regions as $region) {
                foreach ($entity->names as $name) {
                    if ($entity->searchableAlone()) {
                        // Through `compose()` with no placeholder, so a name gets the same squish
                        // and the same 200-character clamp a template does. A watchlist name is
                        // member-typed and reaches a provider URL.
                        $this->push(
                            $queries,
                            $region,
                            self::ENTITY_KEY,
                            $this->compose($name, ''),
                            $tbs,
                            $num,
                            $spec->ignoreDomains,
                        );

                        continue;
                    }

                    foreach ($definitions as $definition) {
                        $qualifier = $this->qualifierFor($definition);

                        if ($qualifier === '') {
                            continue;
                        }

                        $this->push(
                            $queries,
                            $region,
                            $definition->key,
                            $this->compose($name.' '.$qualifier, ''),
                            $tbs,
                            $num,
                            $spec->ignoreDomains,
                        );
                    }
                }
            }
        }

        return $this->plan(array_values($queries), $spec, $maxQueries);
    }

    /**
     * The word that makes a person's name into a question about an event.
     *
     * The definition's first match keyword, which is a stem a team wrote for ICP matching and
     * therefore already the word a sentence about this event would use ("acqui", "rais", "exit").
     * Falls back to the definition's own name, lower-cased, so a type with no keywords still
     * produces something askable rather than nothing.
     */
    private function qualifierFor(SignalDefinition $definition): string
    {
        foreach ($definition->match_keywords ?? [] as $keyword) {
            $clean = Str::squish($keyword);

            if ($clean !== '') {
                return $clean;
            }
        }

        return Str::lower(Str::squish($definition->name));
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
    private function plan(array $queries, SignalQuerySpec $spec, ?int $maxQueries = null): SignalQueryPlan
    {
        $requested = count($queries);
        $max = max(1, $maxQueries ?? (int) config('signals.max_queries_per_run', 40));

        if ($requested <= $max) {
            return new SignalQueryPlan($queries, $requested);
        }

        Log::channel('signals')->warning('Signal run trimmed to the per-run query ceiling.', [
            'team_id' => $spec->teamId,
            // Null for a preview, which is also how this line says which it was.
            'signal_watch_id' => $spec->watchId,
            'requested' => $requested,
            'ceiling' => $max,
        ]);

        return new SignalQueryPlan(array_slice($queries, 0, $max), $requested);
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
        SignalQuerySpec $spec,
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
                    'signal_watch_id' => $spec->watchId,
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
}
