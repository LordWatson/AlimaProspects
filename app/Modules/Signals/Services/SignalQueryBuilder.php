<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\Signals\Data\SignalQuery;
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
 * now seed data for the platform presets only. The watch still supplies the location, which
 * overrides the ICP geography, and its own `ignore_domains`.
 *
 * **The definitions are passed in, not loaded.** That is what keeps this a pure function of its
 * inputs: the caller resolves them through a team-scoped query (which
 * `docs/modules/signals.md` requires of every parent reference, since the FKs are not
 * team-qualified), and S9's "preview this type before you save it" can call the same builder with a
 * definition that has no row yet.
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
     * @return list<SignalQuery>
     */
    public function build(SignalWatch $watch, array $definitions, ?Icp $icp = null): array
    {
        $market = $watch->market->value;
        $location = $this->resolveLocation($watch, $market);
        $gl = (string) config("signals.markets.{$market}.gl", 'ae');
        $hl = (string) config('signals.hl', 'en');
        $tbs = (string) config('signals.recency', 'qdr:w');
        $num = max(1, min((int) config('signals.results_per_query', 20), 100));
        $ignoreDomains = $this->resolveIgnoreDomains($watch);

        /** @var array<string, SignalQuery> $queries keyed by eventKey|q to dedupe */
        $queries = [];

        foreach ($definitions as $definition) {
            foreach ($definition->query_templates as $template) {
                $q = $this->compose($template, $location);
                $this->push($queries, $definition->key, $q, $gl, $hl, $tbs, $num, $ignoreDomains);
            }
        }

        if ($icp instanceof Icp) {
            $this->appendBuyingSignalQueries($watch, $icp, $definitions, $location, $gl, $hl, $tbs, $num, $queries, $ignoreDomains);
        }

        return $this->trim(array_values($queries), $watch);
    }

    /**
     * Hold a run to `signals.max_queries_per_run`.
     *
     * v1 could not exceed eight queries, because the three event types had three, three and two
     * templates between them. S7 lets a team hold 25 types of up to 10 templates each, which is
     * ~250 paid searches for one run of one watch — and neither the per-watch 15-minute throttle
     * nor the per-member rate limit helps, because both bound how *often* a run happens rather than
     * how big one is. This is the ceiling S7 therefore has to carry; S8 keeps it and leans on it
     * harder, since a watch there multiplies templates by locations.
     *
     * The trim is **deterministic** (it keeps the first N, and the definitions arrive in a fixed
     * order) so a team gets the same coverage every run rather than a different slice each time,
     * and it is **logged with a count** so the watch screen can say coverage was reduced. A silent
     * trim is a lie about what was searched.
     *
     * @param  list<SignalQuery>  $queries
     * @return list<SignalQuery>
     */
    private function trim(array $queries, SignalWatch $watch): array
    {
        $max = max(1, (int) config('signals.max_queries_per_run', 40));

        if (count($queries) <= $max) {
            return $queries;
        }

        Log::channel('signals')->warning('Signal run trimmed to the per-run query ceiling.', [
            'team_id' => (int) $watch->team_id,
            'signal_watch_id' => $watch->getKey(),
            'wanted' => count($queries),
            'ceiling' => $max,
        ]);

        return array_slice($queries, 0, $max);
    }

    /**
     * The watch's location, falling back to the market default when it is blank.
     */
    private function resolveLocation(SignalWatch $watch, string $market): string
    {
        $location = trim($watch->location);

        return $location !== '' ? $location : (string) config("signals.markets.{$market}.default_location", '');
    }

    /**
     * Append an extra query per ICP buying signal that maps to one of the watched definitions.
     *
     * @param  list<SignalDefinition>  $definitions
     * @param  array<string, SignalQuery>  $queries
     * @param  list<string>  $ignoreDomains
     */
    private function appendBuyingSignalQueries(
        SignalWatch $watch,
        Icp $icp,
        array $definitions,
        string $location,
        string $gl,
        string $hl,
        string $tbs,
        int $num,
        array &$queries,
        array $ignoreDomains = [],
    ): void {
        foreach ($this->buyingSignals($icp) as $signal) {
            $definition = $this->matchDefinition($signal, $definitions);

            if (! $definition instanceof SignalDefinition) {
                Log::channel('signals')->debug('ICP buying signal ignored for query building: no watched signal type matched its keywords.', [
                    'signal_watch_id' => $watch->getKey(),
                ]);

                continue;
            }

            $q = $this->compose(trim($location.' '.$signal), $location);
            $this->push($queries, $definition->key, $q, $gl, $hl, $tbs, $num, $ignoreDomains);
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
     * What changed is where the keywords live: on the definition, so a team that writes their own
     * type can say which of their buying signals it answers. Word-boundary matching remains the
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
     * Add a query, de-duplicating identical (event key + text) pairs.
     *
     * @param  array<string, SignalQuery>  $queries
     * @param  list<string>  $ignoreDomains
     */
    private function push(array &$queries, string $eventKey, string $q, string $gl, string $hl, string $tbs, int $num, array $ignoreDomains = []): void
    {
        if ($q === '') {
            return;
        }

        $key = $eventKey.'|'.$q;

        if (isset($queries[$key])) {
            return;
        }

        $queries[$key] = new SignalQuery(
            eventKey: $eventKey,
            q: $q,
            gl: $gl,
            hl: $hl,
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
