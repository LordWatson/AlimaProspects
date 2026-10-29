<?php

declare(strict_types=1);

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalQueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->builder = app(SignalQueryBuilder::class);
});

/**
 * @return list<string>
 */
function queryStrings(array $queries): array
{
    return array_map(static fn (SignalQuery $q): string => $q->q, $queries);
}

it('builds the configured templates for the watched event types only', function (): void {
    $watch = SignalWatch::factory()->make([
        'market' => Market::Uae,
        'location' => 'Dubai',
        'event_types' => [SignalEventType::FundingRound->value],
    ]);

    $queries = $this->builder->build($watch);

    expect(queryStrings($queries))->toBe([
        'Dubai startup raises funding',
        'UAE Series B funding',
        'Dubai startup Series A funding round',
    ])
        ->and($queries)->each->toBeInstanceOf(SignalQuery::class);

    foreach ($queries as $q) {
        expect($q->eventType)->toBe(SignalEventType::FundingRound)
            ->and($q->gl)->toBe('ae')
            ->and($q->hl)->toBe('en')
            ->and($q->tbs)->toBe('qdr:w');
    }
});

it('substitutes the watch location into {location} templates', function (): void {
    $watch = SignalWatch::factory()->make([
        'market' => Market::Uae,
        'location' => 'Abu Dhabi',
        'event_types' => [SignalEventType::Relocation->value],
    ]);

    expect(queryStrings($this->builder->build($watch)))->toBe([
        'Abu Dhabi founder relocates',
        'family office Abu Dhabi opens',
    ]);
});

it('falls back to the market default location when the watch location is blank', function (): void {
    $watch = SignalWatch::factory()->make([
        'market' => Market::Uae,
        'location' => '',
        'event_types' => [SignalEventType::Relocation->value],
    ]);

    expect(queryStrings($this->builder->build($watch)))->toContain('Dubai founder relocates');
});

it('covers every watched event type', function (): void {
    $watch = SignalWatch::factory()->make([
        'market' => Market::Uae,
        'location' => 'Dubai',
        'event_types' => SignalEventType::values(),
    ]);

    $queries = $this->builder->build($watch);
    $types = array_values(array_unique(array_map(static fn (SignalQuery $q): string => $q->eventType->value, $queries)));

    expect($types)->toEqualCanonicalizing(SignalEventType::values())
        ->and(count($queries))->toBe(8); // 3 + 3 + 2 templates
});

it('appends an ICP buying signal as an extra query when it maps to a watched event type', function (): void {
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
            'location' => 'Dubai',
            'event_types' => [SignalEventType::FundingRound->value],
        ]);

        return [$watch, $icp->fresh()];
    });

    $queries = queryStrings($this->builder->build($watch, $icp));

    expect($queries)->toContain('Dubai recently raised funding')
        ->and(count($queries))->toBe(4); // 3 templates + 1 buying-signal query
});

it('ignores a buying signal that maps to no event keyword', function (): void {
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
            'location' => 'Dubai',
            'event_types' => [SignalEventType::FundingRound->value],
        ]);

        return [$watch, $icp->fresh()];
    });

    expect(count($this->builder->build($watch, $icp)))->toBe(3); // templates only
});

it('ignores a buying signal that maps to an unwatched event type', function (): void {
    $team = Team::factory()->create();

    [$watch, $icp] = app(TeamContext::class)->run($team, function () use ($team): array {
        $icp = Icp::factory()->create(['team_id' => $team->getKey()]);
        $icp->criteria()->create([
            'team_id' => $team->getKey(),
            'type' => CriterionType::BuyingSignal,
            'value' => 'company acquired by a rival', // maps to Acquisition
            'weight' => 50,
            'position' => 0,
        ]);

        $watch = SignalWatch::factory()->make([
            'team_id' => $team->getKey(),
            'market' => Market::Uae,
            'location' => 'Dubai',
            'event_types' => [SignalEventType::FundingRound->value], // Acquisition not watched
        ]);

        return [$watch, $icp->fresh()];
    });

    expect(count($this->builder->build($watch, $icp)))->toBe(3); // templates only, acquisition signal dropped
});
