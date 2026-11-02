<?php

declare(strict_types=1);

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalQueryBuilder;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * A watch covers places and regions, plural (S8, ADR 0019) — and the cost of that is bounded,
 * deterministic and reported.
 *
 * The expensive mistake this phase could make is arithmetic: the query count is
 * `regions × locations × definitions × templates`, so six types of ten templates over ten places in
 * two regions is twelve hundred paid searches for **one run of one watch**. v1 could not exceed
 * eight. These tests pin the ceiling, the order it trims in, and the fact that it says so.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->builder = app(SignalQueryBuilder::class);
});

/** The team's clone of a catalogue preset, unsaved — the builder takes definitions as arguments. */
function geoDefinition(string $key): SignalDefinition
{
    return SignalDefinition::factory()->fromCatalogue($key)->make();
}

it('asks every template in every place, in every region', function (): void {
    $watch = SignalWatch::factory()
        ->covering(['Dubai', 'Abu Dhabi'], [SearchRegion::Ae, SearchRegion::Gb])
        ->make(['market' => Market::Uae]);

    // One definition with two templates: 2 regions × 2 places × 2 templates = 8.
    $definition = SignalDefinition::factory()->custom()->make([
        'query_templates' => ['{location} hiring surge', '{location} opens office'],
    ]);

    $plan = $this->builder->build($watch, [$definition]);

    expect($plan->count())->toBe(8)
        ->and($plan->requested)->toBe(8)
        ->and($plan->wasTrimmed())->toBeFalse();

    $texts = array_map(static fn (SignalQuery $q): string => $q->q, $plan->queries);

    expect($texts)->toContain('Dubai hiring surge')
        ->and($texts)->toContain('Abu Dhabi hiring surge')
        ->and($texts)->toContain('Dubai opens office')
        ->and($texts)->toContain('Abu Dhabi opens office');

    // The same text in two regions is two different searches — `gl=ae` and `gl=gb` return different
    // news, which is the whole reason a watch can name more than one.
    $dubaiHiring = array_values(array_filter(
        $plan->queries,
        static fn (SignalQuery $q): bool => $q->q === 'Dubai hiring surge',
    ));

    expect($dubaiHiring)->toHaveCount(2)
        ->and(array_map(static fn (SignalQuery $q): string => (string) $q->gl, $dubaiHiring))
        ->toEqualCanonicalizing(['ae', 'gb']);
});

it('carries each region own gl and hl onto its queries', function (): void {
    $watch = SignalWatch::factory()
        ->covering(['London'], [SearchRegion::Gb])
        ->make(['market' => Market::Uk]);

    $plan = $this->builder->build($watch, [geoDefinition(SignalPresetCatalogue::UK_ACQUISITION)]);

    foreach ($plan->queries as $query) {
        expect($query->region)->toBe(SearchRegion::Gb)
            ->and($query->gl)->toBe('gb')
            ->and($query->hl)->toBe('en');
    }
});

it('sends no country code for an unbiased search', function (): void {
    // `global` is not a country, so `gl` is null and `SerperNewsClient` omits it entirely rather
    // than inventing a code for the one search whose point is not to be biased.
    config()->set('signals.regions.global.offered', true);

    $watch = SignalWatch::factory()
        ->covering(['Dubai'], [SearchRegion::Global])
        ->make(['market' => Market::Uae]);

    $plan = $this->builder->build($watch, [geoDefinition(SignalPresetCatalogue::ACQUISITION)]);

    expect($plan->count())->toBeGreaterThan(0)
        ->and($plan->queries[0]->gl)->toBeNull()
        ->and($plan->queries[0]->region)->toBe(SearchRegion::Global);
});

it('drops a region the business no longer offers', function (): void {
    // `offered` is a compliance switch, not a feature flag (ADR 0019 §3): a region switched off
    // because nobody has taken a position on its law has to stop being searched, including for the
    // watches that already named it.
    config()->set('signals.regions.gb.offered', false);

    $watch = SignalWatch::factory()
        ->covering(['Dubai', 'London'], [SearchRegion::Ae, SearchRegion::Gb])
        ->make(['market' => Market::Uae]);

    $plan = $this->builder->build($watch, [geoDefinition(SignalPresetCatalogue::ACQUISITION)]);

    foreach ($plan->queries as $query) {
        expect($query->region)->toBe(SearchRegion::Ae);
    }
});

it('falls back to the market region rather than searching nowhere', function (): void {
    // A watch that searched nowhere would complete successfully having found nothing, which is the
    // most confusing failure this feature could have.
    $watch = SignalWatch::factory()->make([
        'market' => Market::Uk,
        'locations' => [],
        'regions' => [],
    ]);

    $plan = $this->builder->build($watch, [geoDefinition(SignalPresetCatalogue::UK_ACQUISITION)]);

    expect($plan->count())->toBeGreaterThan(0)
        ->and($plan->queries[0]->region)->toBe(SearchRegion::Gb)
        // And the places come from the region's own defaults.
        ->and(array_map(static fn (SignalQuery $q): string => $q->q, $plan->queries))
        ->toContain('London company acquired');
});

it('holds a run to the ceiling and reports what it wanted', function (): void {
    config()->set('signals.max_queries_per_run', 5);

    $watch = SignalWatch::factory()
        ->covering(['Dubai', 'Abu Dhabi', 'Sharjah'], [SearchRegion::Ae])
        ->make(['market' => Market::Uae]);

    $definition = SignalDefinition::factory()->custom()->make([
        'query_templates' => ['{location} a', '{location} b', '{location} c', '{location} d'],
    ]);

    $plan = $this->builder->build($watch, [$definition]);

    // 3 places × 4 templates = 12 wanted, 5 run.
    expect($plan->requested)->toBe(12)
        ->and($plan->count())->toBe(5)
        ->and($plan->wasTrimmed())->toBeTrue();
});

it('trims by dropping the last places rather than a slice of every place', function (): void {
    // The trim order is place, then region, then type, then template — and it keeps the first N. So
    // a cut-down watch loses whole places, which is a gap a member can see on the card, instead of
    // a third of every type's templates everywhere, which is one nobody could.
    config()->set('signals.max_queries_per_run', 2);

    $watch = SignalWatch::factory()
        ->covering(['Dubai', 'London'], [SearchRegion::Ae])
        ->make(['market' => Market::Uae]);

    $definition = SignalDefinition::factory()->custom()->make([
        'query_templates' => ['{location} a', '{location} b'],
    ]);

    $plan = $this->builder->build($watch, [$definition]);

    expect(array_map(static fn (SignalQuery $q): string => $q->q, $plan->queries))
        ->toBe(['Dubai a', 'Dubai b']);
});

it('is deterministic, so coverage does not shuffle between runs', function (): void {
    config()->set('signals.max_queries_per_run', 3);

    $watch = SignalWatch::factory()
        ->covering(['Dubai', 'London', 'Riyadh'], [SearchRegion::Ae])
        ->make(['market' => Market::Uae]);

    $definitions = [
        geoDefinition(SignalPresetCatalogue::ACQUISITION),
        geoDefinition(SignalPresetCatalogue::FUNDING_ROUND),
    ];

    $first = $this->builder->build($watch, $definitions);
    $second = $this->builder->build($watch, $definitions);

    expect(array_map(static fn (SignalQuery $q): string => $q->q, $first->queries))
        ->toBe(array_map(static fn (SignalQuery $q): string => $q->q, $second->queries));
});

it('uses a region translated templates where the definition has them', function (): void {
    // `hl` per region is what unlocks Arabic (ADR 0019 §5). The plumbing ships in S8; the Arabic
    // templates themselves are a content task, so this fixture supplies them.
    config()->set('signals.regions.ae.hl', 'ar');

    $watch = SignalWatch::factory()
        ->covering(['Dubai'], [SearchRegion::Ae])
        ->make(['market' => Market::Uae]);

    $definition = SignalDefinition::factory()->custom()->make([
        'query_templates' => ['{location} company acquisition'],
        'query_templates_i18n' => ['ar' => ['استحواذ شركة {location}']],
    ]);

    $plan = $this->builder->build($watch, [$definition]);

    expect(array_map(static fn (SignalQuery $q): string => $q->q, $plan->queries))
        ->toBe(['استحواذ شركة Dubai'])
        ->and($plan->queries[0]->hl)->toBe('ar');
});

it('falls back to the English templates for a type nobody has translated', function (): void {
    // **The fallback is the point.** Adding an Arabic-language region must never silently empty a
    // watch, and a type with no translation is the normal case rather than an error.
    config()->set('signals.regions.ae.hl', 'ar');

    $watch = SignalWatch::factory()
        ->covering(['Dubai'], [SearchRegion::Ae])
        ->make(['market' => Market::Uae]);

    $definition = SignalDefinition::factory()->custom()->make([
        'query_templates' => ['{location} company acquisition'],
        'query_templates_i18n' => null,
    ]);

    $plan = $this->builder->build($watch, [$definition]);

    expect(array_map(static fn (SignalQuery $q): string => $q->q, $plan->queries))
        ->toBe(['Dubai company acquisition'])
        // The language still goes to Serper: English keywords with `hl=ar` is a worse search than
        // Arabic ones, and a better one than none at all.
        ->and($plan->queries[0]->hl)->toBe('ar');
});

it('produces exactly v1 queries for a watch the S8 backfill touched', function (): void {
    // The backfill gave every v1 watch one place and one region, so its queries must be **byte
    // identical** to what it ran before S8 — otherwise a migration quietly changed what every
    // existing customer's watches find.
    $team = Team::factory()->create();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
        'market' => Market::Uae,
        'locations' => ['Dubai'],
        'regions' => [SearchRegion::Ae->value],
    ]));

    $definitions = array_map(geoDefinition(...), SignalPresetCatalogue::keysForMarket(Market::Uae));

    $plan = $this->builder->build($watch, $definitions);

    expect(array_map(static fn (SignalQuery $q): string => $q->q, $plan->queries))->toBe([
        // acquisition
        'Dubai company acquisition',
        'UAE acquisition deal',
        'UAE startup acquired',
        // funding_round
        'Dubai startup raises funding',
        'UAE Series B funding',
        'Dubai startup Series A funding round',
        // relocation
        'Dubai founder relocates',
        'family office Dubai opens',
    ]);

    foreach ($plan->queries as $query) {
        expect($query->gl)->toBe('ae')
            ->and($query->hl)->toBe('en')
            ->and($query->tbs)->toBe('qdr:w');
    }
});

/*
 * Found by the S8 review: the plan was first nested region-major, so a ceiling smaller than one
 * region's worth of queries spent the whole budget on the first region and left the second with
 * **nothing**. A member who asked for two jurisdictions silently got one, and a count of trimmed
 * queries is not a thing anybody could read that out of.
 */

it('keeps every region covered when a run is trimmed', function (): void {
    config()->set('signals.max_queries_per_run', 4);

    $watch = SignalWatch::factory()
        ->covering(['Dubai', 'London', 'Sharjah'], [SearchRegion::Ae, SearchRegion::Gb])
        ->make(['market' => Market::Uae]);

    $definition = SignalDefinition::factory()->custom()->make([
        'query_templates' => ['{location} a', '{location} b'],
    ]);

    $plan = $this->builder->build($watch, [$definition]);

    // 3 places × 2 regions × 2 templates = 12 wanted, 4 run.
    expect($plan->requested)->toBe(12)
        ->and($plan->count())->toBe(4)
        ->and($plan->wasTrimmed())->toBeTrue();

    $regions = array_values(array_unique(
        array_map(static fn (SignalQuery $q): string => $q->region->value, $plan->queries),
    ));

    expect($regions)->toEqualCanonicalizing(['ae', 'gb']);

    // And what it lost is the *later places*, whole — Dubai in both regions, nothing of Sharjah.
    expect(array_map(static fn (SignalQuery $q): string => $q->q, $plan->queries))
        ->toBe(['Dubai a', 'Dubai b', 'Dubai a', 'Dubai b']);
});

it('searches every type in the first place before moving on', function (): void {
    // Type sits above template, so a trimmed run still covers each type somewhere — as long as one
    // place's worth fits, which is the honest bound and the one the docblock now states.
    config()->set('signals.max_queries_per_run', 3);

    $watch = SignalWatch::factory()
        ->covering(['Dubai', 'London'], [SearchRegion::Ae])
        ->make(['market' => Market::Uae]);

    $definitions = [
        SignalDefinition::factory()->custom()->make(['key' => 'one', 'query_templates' => ['{location} one']]),
        SignalDefinition::factory()->custom()->make(['key' => 'two', 'query_templates' => ['{location} two']]),
        SignalDefinition::factory()->custom()->make(['key' => 'three', 'query_templates' => ['{location} three']]),
    ];

    $plan = $this->builder->build($watch, $definitions);

    expect(array_map(static fn (SignalQuery $q): string => $q->eventKey, $plan->queries))
        ->toEqualCanonicalizing(['one', 'two', 'three']);
});
