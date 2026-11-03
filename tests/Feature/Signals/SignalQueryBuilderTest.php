<?php

declare(strict_types=1);

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\Signals\Data\SignalQueryPlan;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalQueryBuilder;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->builder = app(SignalQueryBuilder::class);
});

/**
 * The query texts of a plan, in order.
 *
 * The builder returns a {@see SignalQueryPlan} since S8 rather than a bare list, because a caller
 * has to be able to tell forty queries from forty queries trimmed down from six hundred.
 *
 * @return list<string>
 */
function queryStrings(SignalQueryPlan $plan): array
{
    return array_map(static fn (SignalSourceQuery $q): string => $q->q, $plan->queries);
}

/**
 * The team's clone of a catalogue preset, unsaved — the builder takes definitions as arguments and
 * never loads them (ADR 0018), so a `make()` is all these tests need.
 */
function definitionFor(string $key): SignalDefinition
{
    return SignalDefinition::factory()->fromCatalogue($key)->make();
}

it('builds the templates of the definitions it is given, and no others', function (): void {
    $watch = SignalWatch::factory()->make([
        'market' => Market::Uae,
        'locations' => ['Dubai'],
    ]);

    $queries = $this->builder->build($watch, [definitionFor(SignalPresetCatalogue::FUNDING_ROUND)]);

    expect(queryStrings($queries))->toBe([
        'Dubai startup raises funding',
        'UAE Series B funding',
        'Dubai startup Series A funding round',
    ])
        ->and($queries->queries)->each->toBeInstanceOf(SignalSourceQuery::class);

    foreach ($queries->queries as $q) {
        expect($q->eventKey)->toBe(SignalPresetCatalogue::FUNDING_ROUND)
            ->and($q->gl)->toBe('ae')
            ->and($q->hl)->toBe('en')
            ->and($q->tbs)->toBe('qdr:w');
    }
});

it('substitutes the watch location into {location} templates', function (): void {
    $watch = SignalWatch::factory()->make([
        'market' => Market::Uae,
        'locations' => ['Abu Dhabi'],
    ]);

    $queries = $this->builder->build($watch, [definitionFor(SignalPresetCatalogue::RELOCATION)]);

    expect(queryStrings($queries))->toBe([
        'Abu Dhabi founder relocates',
        'family office Abu Dhabi opens',
    ]);
});

it('falls back to the market default location when the watch location is blank', function (): void {
    $watch = SignalWatch::factory()->make([
        'market' => Market::Uae,
        'locations' => [],
    ]);

    $queries = $this->builder->build($watch, [definitionFor(SignalPresetCatalogue::RELOCATION)]);

    expect(queryStrings($queries))->toContain('Dubai founder relocates');
});

it('covers every definition it is given', function (): void {
    $watch = SignalWatch::factory()->make([
        'market' => Market::Uae,
        'locations' => ['Dubai'],
    ]);

    // v1's three, named rather than asked for: `keysForMarket` answers a different question
    // since S9 filled the library out into vertical packs, and the count below is about these.
    $keys = [
        SignalPresetCatalogue::ACQUISITION,
        SignalPresetCatalogue::FUNDING_ROUND,
        SignalPresetCatalogue::RELOCATION,
    ];

    $definitions = array_map(definitionFor(...), $keys);

    $queries = $this->builder->build($watch, $definitions);
    $built = array_values(array_unique(array_map(static fn (SignalSourceQuery $q): string => $q->eventKey, $queries->queries)));

    expect($built)->toEqualCanonicalizing($keys)
        ->and($queries->count())->toBe(8); // 3 + 3 + 2 templates
});

it('builds a hand-authored definition from its own templates', function (): void {
    // The point of S7: a type nobody shipped, whose templates are a row rather than config.
    $definition = SignalDefinition::factory()->custom()->make([
        'query_templates' => ['{location} company hiring surge', '{location} opens new office'],
    ]);

    $watch = SignalWatch::factory()->make(['market' => Market::Uae, 'locations' => ['Dubai']]);

    $plan = $this->builder->build($watch, [$definition]);

    expect(queryStrings($plan))->toBe([
        'Dubai company hiring surge',
        'Dubai opens new office',
    ])
        ->and($plan->queries[0]->eventKey)->toBe('hiring_surge');
});

it('reads the templates off the definition, not out of config', function (): void {
    // The definition wins even where a preset of the same key exists in config: `config.templates`
    // is seed data only since S7, and the builder must not consult it.
    config()->set('signals.templates.uae.funding_round', ['config template that must not appear']);

    $watch = SignalWatch::factory()->make(['market' => Market::Uae, 'locations' => ['Dubai']]);

    $queries = queryStrings($this->builder->build($watch, [definitionFor(SignalPresetCatalogue::FUNDING_ROUND)]));

    expect($queries)->not->toContain('config template that must not appear')
        ->and($queries)->toContain('Dubai startup raises funding');
});

it('de-duplicates identical templates across definitions', function (): void {
    $one = SignalDefinition::factory()->custom('type_one', 'Type one')->make([
        'query_templates' => ['{location} company sold'],
    ]);
    $two = SignalDefinition::factory()->custom('type_two', 'Type two')->make([
        'query_templates' => ['{location} company sold'],
    ]);

    $watch = SignalWatch::factory()->make(['market' => Market::Uae, 'locations' => ['Dubai']]);

    // Same text, different keys — so both are kept, because a result has to be attributable to the
    // type that asked for it.
    expect($this->builder->build($watch, [$one, $two])->count())->toBe(2);

    // Same text, same key — one query.
    expect($this->builder->build($watch, [$one, $one])->count())->toBe(1);
});

it('appends an ICP buying signal as an extra query when it maps to a watched definition', function (): void {
    $team = Team::factory()->create();

    [$watch, $icp] = app(TeamContext::class)->run($team, function () use ($team): array {
        $icp = Icp::factory()->create(['team_id' => $team->getKey()]);
        $icp->criteria()->create([
            'team_id' => $team->getKey(),
            'type' => CriterionType::BuyingSignal,
            'value' => 'recently raised funding',
            'weight' => 50,
            'position' => 0,
        ]);

        $watch = SignalWatch::factory()->make([
            'team_id' => $team->getKey(),
            'market' => Market::Uae,
            'locations' => ['Dubai'],
        ]);

        return [$watch, $icp->fresh()];
    });

    $plan = $this->builder->build(
        $watch,
        [definitionFor(SignalPresetCatalogue::FUNDING_ROUND)],
        $icp,
    );

    expect(queryStrings($plan))->toContain('Dubai recently raised funding')
        ->and($plan->count())->toBe(4); // 3 templates + 1 buying-signal query
});

it('ignores a buying signal that matches no definition keyword', function (): void {
    $team = Team::factory()->create();

    [$watch, $icp] = app(TeamContext::class)->run($team, function () use ($team): array {
        $icp = Icp::factory()->create(['team_id' => $team->getKey()]);
        $icp->criteria()->create([
            'team_id' => $team->getKey(),
            'type' => CriterionType::BuyingSignal,
            'value' => 'uses Shopify',
            'weight' => 50,
            'position' => 0,
        ]);

        $watch = SignalWatch::factory()->make([
            'team_id' => $team->getKey(),
            'market' => Market::Uae,
            'locations' => ['Dubai'],
        ]);

        return [$watch, $icp->fresh()];
    });

    $queries = $this->builder->build($watch, [definitionFor(SignalPresetCatalogue::FUNDING_ROUND)], $icp);

    expect($queries->count())->toBe(3); // templates only
});

it('ignores a buying signal whose keywords belong to a definition this watch does not use', function (): void {
    $team = Team::factory()->create();

    [$watch, $icp] = app(TeamContext::class)->run($team, function () use ($team): array {
        $icp = Icp::factory()->create(['team_id' => $team->getKey()]);
        $icp->criteria()->create([
            'team_id' => $team->getKey(),
            'type' => CriterionType::BuyingSignal,
            // Matches the acquisition type's keywords, which this watch is not given.
            'value' => 'company acquired by a rival',
            'weight' => 50,
            'position' => 0,
        ]);

        $watch = SignalWatch::factory()->make([
            'team_id' => $team->getKey(),
            'market' => Market::Uae,
            'locations' => ['Dubai'],
        ]);

        return [$watch, $icp->fresh()];
    });

    $queries = $this->builder->build($watch, [definitionFor(SignalPresetCatalogue::FUNDING_ROUND)], $icp);

    expect($queries->count())->toBe(3); // templates only; the acquisition signal is dropped
});

it('matches an ambiguous buying signal to the first definition given, deterministically', function (): void {
    // v1 relied on the enum's declaration order for this (acquisition before funding before
    // relocation). With types as rows the promise moves to the order the caller passes them in,
    // which `SignalWatch::definitions()` fixes by definition id — so this test pins the rule
    // rather than the enum that used to carry it.
    $team = Team::factory()->create();

    [$watch, $icp] = app(TeamContext::class)->run($team, function () use ($team): array {
        $icp = Icp::factory()->create(['team_id' => $team->getKey()]);
        $icp->criteria()->create([
            'team_id' => $team->getKey(),
            'type' => CriterionType::BuyingSignal,
            // "investment" is a funding keyword; "sold" is an acquisition keyword. Both match.
            'value' => 'sold a stake to an investment firm',
            'weight' => 50,
            'position' => 0,
        ]);

        return [
            SignalWatch::factory()->make([
                'team_id' => $team->getKey(),
                'market' => Market::Uae,
                'locations' => ['Dubai'],
            ]),
            $icp->fresh(),
        ];
    });

    $acquisition = definitionFor(SignalPresetCatalogue::ACQUISITION);
    $funding = definitionFor(SignalPresetCatalogue::FUNDING_ROUND);

    $acquisitionFirst = $this->builder->build($watch, [$acquisition, $funding], $icp);
    $fundingFirst = $this->builder->build($watch, [$funding, $acquisition], $icp);

    $extra = static fn (SignalQueryPlan $plan): SignalSourceQuery => collect($plan->queries)
        ->firstOrFail(static fn (SignalSourceQuery $q): bool => $q->q === 'Dubai sold a stake to an investment firm');

    expect($extra($acquisitionFirst)->eventKey)->toBe(SignalPresetCatalogue::ACQUISITION)
        ->and($extra($fundingFirst)->eventKey)->toBe(SignalPresetCatalogue::FUNDING_ROUND);
});
