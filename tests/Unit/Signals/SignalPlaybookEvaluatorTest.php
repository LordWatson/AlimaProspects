<?php

declare(strict_types=1);

use App\Modules\Signals\Data\SignalPlaybookConditions;
use App\Modules\Signals\Data\SignalPlaybookFacts;
use App\Modules\Signals\Enums\PlaybookMode;
use App\Modules\Signals\Enums\PlaybookWatchlistCondition;
use App\Modules\Signals\Models\SignalPlaybook;
use App\Modules\Signals\Services\SignalPlaybookEvaluator;

/**
 * The matcher, as a table (S14).
 *
 * A unit test with no database at all, which is the point of {@see SignalPlaybookFacts} existing:
 * the evaluator is the thing a member's preview and their dry run are both predictions about, so it
 * has to be deterministic, and eight independent clauses are only safe if every one of them is
 * pinned both ways.
 *
 * Two behaviours get their own tests because they are the ones a naive implementation gets
 * backwards: an **empty list means "don't care"**, and **unknown is not zero**.
 */
function playbookFacts(array $overrides = []): SignalPlaybookFacts
{
    return new SignalPlaybookFacts(
        // `array_key_exists` rather than `??` for every nullable field: a fixture overriding one
        // to **null** is the interesting case here, and `??` would quietly hand back the default.
        definitionId: array_key_exists('definitionId', $overrides) ? $overrides['definitionId'] : 7,
        eventKey: $overrides['eventKey'] ?? 'funding_round',
        sourceKind: $overrides['sourceKind'] ?? 'news',
        jurisdiction: array_key_exists('jurisdiction', $overrides) ? $overrides['jurisdiction'] : 'GB',
        heat: array_key_exists('heat', $overrides) ? $overrides['heat'] : 70,
        amountMinor: array_key_exists('amountMinor', $overrides) ? $overrides['amountMinor'] : 500_000_00,
        hasMatchedLead: $overrides['hasMatchedLead'] ?? false,
        watchlistId: array_key_exists('watchlistId', $overrides) ? $overrides['watchlistId'] : null,
        isClusterRepresentative: $overrides['isClusterRepresentative'] ?? true,
    );
}

function evaluator(): SignalPlaybookEvaluator
{
    return new SignalPlaybookEvaluator;
}

it('matches everything when nothing is set', function (): void {
    expect(evaluator()->matches(new SignalPlaybookConditions, playbookFacts()))->toBeTrue();
});

it('says so when a rule narrows nothing', function (): void {
    expect((new SignalPlaybookConditions)->matchesEverything())->toBeTrue();
    expect((new SignalPlaybookConditions(minHeat: 1))->matchesEverything())->toBeFalse();
});

describe('each condition matches and fails', function (): void {
    it('matches on signal type', function (): void {
        $conditions = new SignalPlaybookConditions(definitionIds: [7, 9]);

        expect(evaluator()->matches($conditions, playbookFacts(['definitionId' => 7])))->toBeTrue();
        expect(evaluator()->matches($conditions, playbookFacts(['definitionId' => 8])))->toBeFalse();
    });

    it('does not match a type-scoped rule when the definition is gone', function (): void {
        // The signal keeps `event_key`, but a rule names an id — silently matching on a key the
        // member never chose would be a different rule from the one they wrote.
        $conditions = new SignalPlaybookConditions(definitionIds: [7]);

        expect(evaluator()->matches($conditions, playbookFacts(['definitionId' => null])))->toBeFalse();
    });

    it('matches on source', function (): void {
        $conditions = new SignalPlaybookConditions(sourceKinds: ['news']);

        expect(evaluator()->matches($conditions, playbookFacts(['sourceKind' => 'news'])))->toBeTrue();
        expect(evaluator()->matches($conditions, playbookFacts(['sourceKind' => 'web_search'])))->toBeFalse();
    });

    it('matches on jurisdiction, and refuses an unplaceable story', function (): void {
        $conditions = new SignalPlaybookConditions(jurisdictions: ['GB']);

        expect(evaluator()->matches($conditions, playbookFacts(['jurisdiction' => 'GB'])))->toBeTrue();
        expect(evaluator()->matches($conditions, playbookFacts(['jurisdiction' => 'AE'])))->toBeFalse();
        // Fails **toward** the condition, which is the conservative direction for a rule that may
        // go on to draft an approach (docs/compliance.md §3.11's reasoning, one level up).
        expect(evaluator()->matches($conditions, playbookFacts(['jurisdiction' => null])))->toBeFalse();
    });

    it('matches on a heat floor', function (): void {
        $conditions = new SignalPlaybookConditions(minHeat: 70);

        expect(evaluator()->matches($conditions, playbookFacts(['heat' => 70])))->toBeTrue();
        expect(evaluator()->matches($conditions, playbookFacts(['heat' => 69])))->toBeFalse();
    });

    it('matches on an amount band, both ends inclusive', function (): void {
        $conditions = new SignalPlaybookConditions(minAmountMinor: 100_00, maxAmountMinor: 200_00);

        expect(evaluator()->matches($conditions, playbookFacts(['amountMinor' => 100_00])))->toBeTrue();
        expect(evaluator()->matches($conditions, playbookFacts(['amountMinor' => 200_00])))->toBeTrue();
        expect(evaluator()->matches($conditions, playbookFacts(['amountMinor' => 99_99])))->toBeFalse();
        expect(evaluator()->matches($conditions, playbookFacts(['amountMinor' => 200_01])))->toBeFalse();
    });

    it('matches on whether the event is about a company the team already has', function (): void {
        $matched = new SignalPlaybookConditions(watchlist: PlaybookWatchlistCondition::Matched);
        $unmatched = new SignalPlaybookConditions(watchlist: PlaybookWatchlistCondition::Unmatched);

        expect(evaluator()->matches($matched, playbookFacts(['hasMatchedLead' => true])))->toBeTrue();
        expect(evaluator()->matches($matched, playbookFacts(['hasMatchedLead' => false])))->toBeFalse();
        expect(evaluator()->matches($unmatched, playbookFacts(['hasMatchedLead' => false])))->toBeTrue();
        expect(evaluator()->matches($unmatched, playbookFacts(['hasMatchedLead' => true])))->toBeFalse();
    });

    it('matches on a named watchlist', function (): void {
        $conditions = new SignalPlaybookConditions(watchlistIds: [3]);

        expect(evaluator()->matches($conditions, playbookFacts(['watchlistId' => 3])))->toBeTrue();
        expect(evaluator()->matches($conditions, playbookFacts(['watchlistId' => 4])))->toBeFalse();
        expect(evaluator()->matches($conditions, playbookFacts(['watchlistId' => null])))->toBeFalse();
    });
});

describe('unknown is not zero', function (): void {
    it('does not clear a heat floor with an unscored signal', function (): void {
        // `signals.heat` is nullable because null means "not scored" (ADR 0022). Treating it as
        // zero would make a rule about hot events fire on everything Alima has not judged.
        $conditions = new SignalPlaybookConditions(minHeat: 1);

        expect(evaluator()->matches($conditions, playbookFacts(['heat' => null])))->toBeFalse();
    });

    it('does not put a signal with no amount inside an amount band', function (): void {
        $conditions = new SignalPlaybookConditions(minAmountMinor: 0);

        expect(evaluator()->matches($conditions, playbookFacts(['amountMinor' => null])))->toBeFalse();
    });

    it('still matches an unscored signal when no floor is set', function (): void {
        expect(evaluator()->matches(new SignalPlaybookConditions, playbookFacts(['heat' => null])))->toBeTrue();
    });
});

/** A playbook stand-in with no database behind it: the evaluator asks it only three things. */
function playbookStub(bool $stops, PlaybookMode $mode = PlaybookMode::Live): SignalPlaybook
{
    $playbook = Mockery::mock(SignalPlaybook::class)->makePartial();
    $playbook->stop_after_first_match = $stops;
    $playbook->mode = $mode;
    $playbook->shouldReceive('conditionSet')->andReturn(new SignalPlaybookConditions);

    return $playbook;
}

describe('selection', function (): void {
    it('runs the rules in order and honours stop-after-first-match', function (): void {
        $first = playbookStub(stops: true);
        $second = playbookStub(stops: false);

        $firing = evaluator()->select([$first, $second], playbookFacts());

        expect($firing)->toHaveCount(1);
        expect($firing[0])->toBe($first);
    });

    it('does not let a rehearsing rule stop the chain', function (): void {
        // The one promise a rehearsal makes is that it changes nothing, and that has to include
        // the other rules: a dry-run rule with the flag set would otherwise swallow the signal for
        // the live rule below it, so switching a rule to rehearse would change what the rest of
        // the team's automation did.
        $rehearsing = playbookStub(stops: true, mode: PlaybookMode::DryRun);
        $live = playbookStub(stops: false);

        expect(evaluator()->select([$rehearsing, $live], playbookFacts()))->toHaveCount(2);
    });

    it('skips a cluster member entirely', function (): void {
        // One event is one card and a member never actions a member row (S11), so a rule must not
        // either — eight rules firing on eight articles about one acquisition would convert the
        // same company eight times.
        expect(evaluator()->select([playbookStub(stops: false)], playbookFacts(['isClusterRepresentative' => false])))
            ->toBe([]);
    });
});
