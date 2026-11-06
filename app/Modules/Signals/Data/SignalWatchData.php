<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Core\Enums\Market;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Enums\SignalWatchCadence;
use App\Modules\Signals\Http\Requests\SaveSignalWatchRequest;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalQueryBuilder;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated input for creating or updating a {@see SignalWatch} (from
 * {@see SaveSignalWatchRequest}).
 *
 * `market` is **not** taken from the form, and since S8 it means something narrower than it used
 * to (ADR 0019). It is the team's **declared compliance regime** for this watch — a backstop for a
 * signal whose own jurisdiction cannot be resolved, and the seed for the watch's first region. It
 * no longer decides where to look: that is `regions`, and each region carries its own Serper `gl`
 * and `hl`. The value comes from the **team's** market rather than a product-wide constant, which
 * is what makes a UK team's first watch a UK watch.
 *
 * Since S7 the watched types are **definition ids**, not enum values (ADR 0018): a signal type is a
 * row the team owns. The ids are carried as plain integers here and resolved to the team's own rows
 * by `SaveSignalWatchAction` — a DTO that queried would be a DTO that could not be built from a
 * request.
 */
final class SignalWatchData extends Data
{
    /**
     * The market a watch falls back to when the team has none recorded.
     *
     * Kept because the preset library and the one-click first watch both need *a* market to filter
     * and seed from, and a team row with a missing or unrecognised `market` must not take a screen
     * down. Prefer {@see self::marketFor()} over this constant: reading the team's own market is the
     * point of S8.
     */
    public const Market DEFAULT_MARKET = Market::Uae;

    private const int MAX_NAME = 120;

    private const int MAX_LOCATION = 120;

    /** A sensible ceiling on a hand-kept exclusion list — beyond this it isn't a list, it's a feed. */
    private const int MAX_IGNORE_DOMAINS = 50;

    /**
     * How many types one watch may name. The real ceiling is the team's library
     * (`signals.max_definitions_per_team`); this is the shape bound, so a tampered form cannot
     * post ten thousand ids for the Action to resolve.
     */
    public const int MAX_DEFINITIONS = 50;

    /**
     * @param  list<string>  $locations  The places this watch covers (S8). Never empty.
     * @param  list<SearchRegion>  $regions  Where to search (S8). Never empty.
     * @param  list<int>  $definitionIds  The team's signal types this watch watches for.
     * @param  list<string>  $ignoreDomains  Sources this team never wants to hear from (S6).
     * @param  int|null  $watchlistId  The accounts this watch follows instead of places (S10). Null
     *                                 is a **topical** watch, which is what every watch was before
     *                                 S10 — the two are exclusive, and the column is the difference
     *                                 rather than a flag (ADR 0021 §1). The places stay on the row
     *                                 either way, because unpointing a watch should give it back
     *                                 the geography the member chose rather than an empty form.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $locations,
        public readonly array $regions,
        public readonly array $definitionIds,
        public readonly SignalWatchCadence $cadence = SignalWatchCadence::Daily,
        public readonly bool $isActive = true,
        public readonly ?int $icpId = null,
        public readonly array $ignoreDomains = [],
        public readonly Market $market = self::DEFAULT_MARKET,
        public readonly ?int $watchlistId = null,
    ) {}

    /**
     * The compliance regime a watch of this team's falls under.
     *
     * `Team::market` is a plain string with a `'uk'` default rather than an enum cast, so an
     * unrecognised value resolves to {@see self::DEFAULT_MARKET} instead of throwing — a team row
     * somebody edited by hand must not take the watch screen down.
     */
    public static function marketFor(?string $teamMarket): Market
    {
        return Market::tryFrom((string) $teamMarket) ?? self::DEFAULT_MARKET;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated, ?Market $market = null): self
    {
        $market ??= self::DEFAULT_MARKET;
        $regions = self::regions($validated['regions'] ?? null, $market);

        return new self(
            name: self::text($validated['name'] ?? null, self::MAX_NAME) ?? self::defaultName($market),
            locations: self::locations($validated['locations'] ?? null, $regions),
            regions: $regions,
            definitionIds: self::ids($validated['signal_definition_ids'] ?? null),
            cadence: SignalWatchCadence::tryFrom((string) ($validated['cadence'] ?? '')) ?? SignalWatchCadence::Daily,
            // A missing checkbox is false in a form post, so "absent" has to mean the default
            // rather than being read as "switch it off".
            isActive: array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
            icpId: isset($validated['icp_id']) ? (int) $validated['icp_id'] : null,
            ignoreDomains: self::domains($validated['ignore_domains'] ?? null),
            market: $market,
            watchlistId: isset($validated['signal_watchlist_id'])
                ? max(1, (int) $validated['signal_watchlist_id'])
                : null,
        );
    }

    /**
     * The one-click default watch for a team that has none (S5): the region's default places, every
     * type the team has, daily. Deliberately the broadest useful watch — a first watch that finds
     * nothing teaches the team that Signals doesn't work.
     *
     * The ids come from the caller rather than from here, because "every type" is a question about
     * the team's library: the controller clones the market's starter presets first
     * (`CloneSignalPresetsAction`) and passes what it got. The **market** comes from the caller too
     * since S8, because a UK team's first watch should look at the UK.
     *
     * @param  list<int>  $definitionIds
     */
    public static function defaultWatch(array $definitionIds = [], ?Market $market = null): self
    {
        $market ??= self::DEFAULT_MARKET;
        $region = SearchRegion::forMarket($market);

        return new self(
            name: self::defaultName($market),
            locations: self::locations(null, [$region]),
            regions: [$region],
            definitionIds: $definitionIds,
            cadence: SignalWatchCadence::Daily,
            market: $market,
        );
    }

    /**
     * The attributes for the model, ready to create or fill with.
     *
     * The watched types are **not** here: they live in the `signal_watch_definition` pivot, and
     * attaching them is a separate statement inside the Action's transaction (ADR 0018 §5).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'market' => $this->market,
            'locations' => $this->locations,
            'regions' => array_map(static fn (SearchRegion $region): string => $region->value, $this->regions),
            'cadence' => $this->cadence,
            'is_active' => $this->isActive,
            'icp_id' => $this->icpId,
            // Null rather than [] when empty, so "never configured" stays distinguishable from
            // "deliberately empty" (see the migration).
            'ignore_domains' => $this->ignoreDomains === [] ? null : $this->ignoreDomains,
            'signal_watchlist_id' => $this->watchlistId,
        ];
    }

    /**
     * The validation rules a saved watch must satisfy, beside the shape they validate so the web
     * (S5) and API (S6) endpoints can't drift apart on what a watch may be.
     *
     * The ids are **not** checked against the team's own rows here, and that is deliberate: an id
     * from another team is dropped and logged by the Action rather than rejected, exactly as an
     * out-of-team ICP is. It can only arrive from a tampered form, and dropping it saves a watch
     * the member had every right to save.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::MAX_NAME],
            'locations' => ['required', 'array', 'min:1', 'max:'.self::maxLocations()],
            'locations.*' => ['string', 'max:'.self::MAX_LOCATION],
            // **Only the regions the business has a compliance position for.** `offered` is not a
            // feature flag: choosing where to look also chooses how the resulting leads may be
            // approached, and a region with no recorded position would mean leads nobody has
            // decided the rules for (ADR 0019 §3).
            'regions' => ['required', 'array', 'min:1', 'max:'.self::maxRegions()],
            'regions.*' => ['string', 'distinct', 'in:'.implode(',', SearchRegion::offeredValues())],
            'signal_definition_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_DEFINITIONS],
            'signal_definition_ids.*' => ['integer', 'distinct', 'min:1'],
            'cadence' => ['required', 'string', 'in:'.implode(',', SignalWatchCadence::values())],
            'is_active' => ['boolean'],
            'icp_id' => ['nullable', 'integer'],
            // The list this watch follows (S10). Existence is checked **against the member's own
            // team** in the FormRequest, not here, for the same reason `icp_id` is: this file
            // describes a shape and the request knows who is asking. Unlike a definition id, an
            // out-of-team value is *rejected* rather than dropped — a watch silently pointed at
            // nothing would look configured and search places instead.
            'signal_watchlist_id' => ['nullable', 'integer', 'min:1'],
            'ignore_domains' => ['nullable', 'array', 'max:'.self::MAX_IGNORE_DOMAINS],
            'ignore_domains.*' => ['string', 'max:255'],
        ];
    }

    private static function defaultName(Market $market): string
    {
        return self::defaultLocation($market).' wealth events';
    }

    private static function defaultLocation(Market $market): string
    {
        $location = config("signals.markets.{$market->value}.default_location");

        if (is_string($location) && $location !== '') {
            return $location;
        }

        return SearchRegion::forMarket($market)->defaultLocations()[0] ?? 'Dubai';
    }

    private static function maxLocations(): int
    {
        return max(1, (int) config('signals.max_locations_per_watch', 10));
    }

    private static function maxRegions(): int
    {
        return max(1, (int) config('signals.max_regions_per_watch', 5));
    }

    /**
     * The places a watch covers, squished, de-duplicated and bounded — never empty.
     *
     * Falls back to the regions' own default places, because a watch that searched nowhere would
     * complete successfully having found nothing, which is the most confusing failure this feature
     * could have. The rules require at least one, so this only fires for the one-click path and for
     * a caller that sent a list of blanks.
     *
     * @param  list<SearchRegion>  $regions
     * @return list<string>
     */
    private static function locations(mixed $value, array $regions): array
    {
        $locations = [];

        foreach (is_array($value) ? $value : [] as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $place = self::text($entry, self::MAX_LOCATION);

            if ($place !== null && ! in_array($place, $locations, strict: true)) {
                $locations[] = $place;
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

        return array_slice($locations, 0, self::maxLocations());
    }

    /**
     * The regions a watch searches — never empty, and never one the business has no position on.
     *
     * An unoffered region is **dropped rather than rejected**, the same treatment an out-of-team
     * ICP or signal type gets: it can only arrive from a tampered form or from a region switched
     * off since the watch was saved, and in both cases saving the watch without it is better than
     * refusing a change the member had every right to make. Falls back to the region matching their
     * market, so the result is always a watch that looks somewhere.
     *
     * @return list<SearchRegion>
     */
    private static function regions(mixed $value, Market $market): array
    {
        $regions = [];

        foreach (is_array($value) ? $value : [] as $entry) {
            $region = is_string($entry) ? SearchRegion::tryFrom($entry) : null;

            if ($region !== null && $region->isOffered() && ! in_array($region, $regions, strict: true)) {
                $regions[] = $region;
            }
        }

        if ($regions === []) {
            $regions[] = SearchRegion::forMarket($market);
        }

        return array_slice($regions, 0, self::maxRegions());
    }

    /**
     * A de-duplicated list of positive integer ids, bounded.
     *
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $entry) {
            if (! is_numeric($entry)) {
                continue;
            }

            $id = (int) $entry;

            if ($id > 0 && ! in_array($id, $ids, strict: true)) {
                $ids[] = $id;
            }
        }

        return array_slice($ids, 0, self::MAX_DEFINITIONS);
    }

    /**
     * The team's exclusion list, as a de-duplicated list of trimmed strings.
     *
     * Deliberately **not** normalised to a host here: the query builder does that at the point of
     * use ({@see SignalQueryBuilder}), so a team that typed a whole URL still sees their own typing
     * when they come back to the form, rather than something the app rewrote behind them.
     *
     * @return list<string>
     */
    private static function domains(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $domains = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $domain = Str::limit(Str::squish($entry), 255, '');

            if ($domain !== '' && ! in_array($domain, $domains, strict: true)) {
                $domains[] = $domain;
            }
        }

        return array_slice($domains, 0, self::MAX_IGNORE_DOMAINS);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $squished = Str::squish($value);

        return $squished === '' ? null : Str::limit($squished, $max, '');
    }
}
