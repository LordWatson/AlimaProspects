<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalQueryBuilder;
use App\Modules\Signals\Support\IgnoredDomains;
use Illuminate\Support\Str;

/**
 * Where to look: the places, the regions and the domains to skip, with no model behind them (S9).
 *
 * **Why this exists.** {@see SignalQueryBuilder} read all of this off
 * a `SignalWatch`, which meant building queries required a **saved** watch — and S9's preview has
 * no watch at all. It previews a definition somebody is still typing, for a team that may not have
 * created a watch yet. A builder that needs a persisted row is the reason previews are hard, and
 * passing it an unsaved model would have been a lie the type system could not catch: half the
 * accessors would work and `getKey()` would return null into a log line.
 *
 * So the "where" moved here and the builder kept the "how". Everything the builder used to resolve
 * — the offered-region filter, the market fallback, the place defaults, the caps, the domain
 * normalisation — resolves **once**, in the factory, which is also the only place that knows how a
 * watch differs from a draft.
 *
 * It is immutable and already bounded: a spec cannot hold more places or regions than the config
 * allows, so nothing downstream has to re-check.
 */
final class SignalQuerySpec
{
    /**
     * @param  list<SearchRegion>  $regions
     * @param  list<string>  $locations
     * @param  list<string>  $ignoreDomains  What goes **on the query**, so the provider's results
     *                                       never include it. Empty for a preview.
     * @param  list<string>  $teamIgnoreDomains  What the team actually listed. A preview compares
     *                                           against this to **label** a skipped source instead
     *                                           of dropping it, which is the one place it differs
     *                                           from a run (ADR 0020 §4). The same list on a watch.
     * @param  list<SignalQueryEntity>  $entities  Named accounts to search for (S10). A spec has
     *                                             **either** places or entities: a watch asks
     *                                             "what happened in Dubai?" or "what happened to
     *                                             these forty companies?", and conflating the two
     *                                             would multiply one cost by the other.
     * @param  int|null  $teamId  For the log line only; a draft belongs to a team, a spec does not.
     * @param  int|null  $watchId  Null for a preview, which is how a log line says which it was.
     */
    private function __construct(
        public readonly array $regions,
        public readonly array $locations,
        public readonly array $ignoreDomains,
        public readonly array $teamIgnoreDomains = [],
        public readonly array $entities = [],
        public readonly ?int $teamId = null,
        public readonly ?int $watchId = null,
    ) {}

    /**
     * The spec an **entity-scoped** run searches by: these accounts, in the watch's own regions.
     *
     * The places are left empty and that is the distinction, not a flag: an entity query is a name,
     * and a name does not want a city appended to it. The region still applies, because `gl` decides
     * which country's press a name is searched in and a UK account is reported by UK outlets.
     *
     * @param  list<SignalQueryEntity>  $entities
     */
    public static function forEntities(SignalWatch $watch, array $entities): self
    {
        $domains = self::domains($watch->ignore_domains ?? []);

        return new self(
            regions: self::regions($watch->regions ?? [], $watch->market),
            locations: [],
            ignoreDomains: $domains,
            teamIgnoreDomains: $domains,
            entities: $entities,
            teamId: (int) $watch->team_id,
            watchId: (int) $watch->getKey(),
        );
    }

    /** Whether this spec is about named accounts rather than places. */
    public function isEntityScoped(): bool
    {
        return $this->entities !== [];
    }

    /**
     * The spec a scheduled or manual run searches by.
     */
    public static function forWatch(SignalWatch $watch): self
    {
        $regions = self::regions($watch->regions ?? [], $watch->market);

        $domains = self::domains($watch->ignore_domains ?? []);

        return new self(
            regions: $regions,
            locations: self::locations($watch->locations ?? [], $regions),
            ignoreDomains: $domains,
            teamIgnoreDomains: $domains,
            teamId: (int) $watch->team_id,
            watchId: (int) $watch->getKey(),
        );
    }

    /**
     * The spec a **preview** searches by: a sample, deliberately, not a rehearsal of a whole run.
     *
     * One region and as many places as the caller allows, because a preview exists to answer "do
     * these templates return anything at all" and the answer does not get truer by paying for it in
     * five cities. `PreviewSignalDefinitionAction` is what picks the region and the places; this
     * only guarantees the result is bounded and non-empty.
     *
     * **`ignoreDomains` is empty on purpose.** A run drops an ignored source silently, which is
     * right — nobody wants a property portal in their feed. A preview has to *show* it, labelled,
     * because "your template works but every result is on your skip list" is the one diagnosis a
     * member could never reach on their own. The labelling happens in the Action, over the same
     * {@see IgnoredDomains} the client uses.
     *
     * @param  list<SearchRegion>  $regions
     * @param  list<string>  $locations
     * @param  list<string>  $ignoreDomains  The standing-in watch's own list, for labelling.
     */
    public static function forDraft(Team $team, array $regions, array $locations, array $ignoreDomains = []): self
    {
        $values = array_map(static fn (SearchRegion $region): string => $region->value, $regions);
        // `teams.market` is a plain string column, not a cast enum — unlike `signal_watches.market`.
        // An unreadable value falls back to the restrictive market rather than to a default, for the
        // same reason ADR 0019 §3 gives: an unknown is not a licence.
        $resolved = self::regions($values, Market::tryFrom($team->market) ?? Market::Uae);

        return new self(
            regions: $resolved,
            locations: self::locations($locations, $resolved),
            ignoreDomains: [],
            teamIgnoreDomains: self::domains($ignoreDomains),
            teamId: (int) $team->getKey(),
            watchId: null,
        );
    }

    /**
     * How many queries a plan built from this spec would ask for, given a template count.
     *
     * The arithmetic of ADR 0019 §4 in one place, so the cost estimate on the form and the plan the
     * builder produces cannot drift. Regions × places × templates.
     */
    public function queryCountFor(int $templates): int
    {
        return count($this->regions) * count($this->locations) * max($templates, 0);
    }

    /**
     * The regions to search: stored, offered, de-duplicated, capped, and never empty.
     *
     * A stored region that is no longer **offered** is dropped rather than honoured: `offered` is a
     * compliance switch (ADR 0019 §2a), so a region switched off because the business has not taken
     * a position on it must stop being searched — including for the watches that already had it.
     * Note that this is the *searching* half only; the restriction backstop reads
     * `SignalWatch::storedRegions()` and deliberately does not filter.
     *
     * @param  list<string>  $values
     * @return list<SearchRegion>
     */
    private static function regions(array $values, Market $fallback): array
    {
        $max = max(1, (int) config('signals.max_regions_per_watch', 5));

        $regions = [];

        foreach ($values as $value) {
            $region = SearchRegion::tryFrom($value);

            if ($region === null || ! $region->isOffered()) {
                continue;
            }

            if (! in_array($region, $regions, strict: true)) {
                $regions[] = $region;
            }
        }

        if ($regions === []) {
            $regions[] = SearchRegion::forMarket($fallback);
        }

        return array_slice($regions, 0, $max);
    }

    /**
     * The places to search: squished, de-duplicated, capped, and never empty.
     *
     * Falls back to the regions' own default places when none is named — something that searched
     * nowhere would complete successfully having found nothing, which is the most confusing failure
     * this feature could have.
     *
     * @param  list<string>  $values
     * @param  list<SearchRegion>  $regions
     * @return list<string>
     */
    private static function locations(array $values, array $regions): array
    {
        $max = max(1, (int) config('signals.max_locations_per_watch', 10));

        $locations = [];

        foreach ($values as $place) {
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
     * The team's own skip list, normalised to hosts the provider's results can actually match.
     *
     * Delegated to {@see IgnoredDomains::normaliseAll()} rather than done here, which is the
     * correction the S9 review forced: this rule existed in two slightly different versions for one
     * phase — the full one here for a run, a shorter one in `IgnoredDomains` for the preview — so a
     * watch holding `https://rival.ae/news` had its results dropped by a run and **kept** by the
     * preview. A preview more lenient than the run is worse than no preview: it shows somebody a
     * result they will never receive. One rule, one place, both sides reading it.
     *
     * @param  list<string>  $entries
     * @return list<string>
     */
    private static function domains(array $entries): array
    {
        return IgnoredDomains::normaliseAll($entries);
    }
}
