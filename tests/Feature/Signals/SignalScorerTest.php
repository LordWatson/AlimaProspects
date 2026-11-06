<?php

declare(strict_types=1);

use App\Modules\Core\Enums\Currency;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\Signals\Data\SignalScore;
use App\Modules\Signals\Enums\SignalScoreComponent;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalSourceStat;
use App\Modules\Signals\Services\SignalScorer;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * `SignalScorer` (S12).
 *
 * The phase's own risk note is what this file is for: *a scorer nobody understands is worse than
 * date order.* So the tests are about the properties a member would expect to hold rather than
 * about the arithmetic reaching a particular number — each component moves the total in the right
 * direction, an unjudgeable component drops out instead of scoring zero, and the same inputs
 * always give the same answer.
 */

uses(RefreshDatabase::class);

/**
 * A signal with everything the scorer can read, so a test can take one thing away and see what
 * happens. Unsaved where it can be: most of these cases are pure arithmetic over columns.
 */
function scorableSignal(Team $team, array $attributes = []): Signal
{
    return new Signal(array_merge([
        'team_id' => $team->getKey(),
        'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
        'headline' => 'Acme closes a $40m Series A',
        'summary' => 'Acme DMCC has raised a Series A.',
        'subject_company_name' => 'Acme DMCC',
        'subject_person_name' => 'Jane Founder',
        'subject_person_role' => 'Founder & CEO',
        'location' => 'Dubai',
        'jurisdiction' => 'AE',
        'confidence' => 80,
        'relevance' => 70,
        'source_name' => 'Gulf Business',
        'source_url' => 'https://gulfbusiness.com/acme',
        'published_at' => now(),
        'amount_minor' => 4_000_000_00,
        'currency' => Currency::Usd,
    ], $attributes));
}

function signalScorer(): SignalScorer
{
    return app(SignalScorer::class);
}

/** One component's row out of a breakdown. */
function componentOf(SignalScore $score, SignalScoreComponent $component): ?object
{
    foreach ($score->components as $row) {
        if ($row->component === $component) {
            return $row;
        }
    }

    return null;
}

// --- It is a score, and it is deterministic -----------------------------------------------------

it('returns the same heat for the same signal every time', function (): void {
    $team = Team::factory()->create();
    $signal = scorableSignal($team);

    // No AI in the scorer: given the same signal, the same history and the same weights it is the
    // same answer, which is what makes the breakdown on the card auditable.
    $first = signalScorer()->score($signal);
    $second = signalScorer()->score($signal);

    expect($first->heat)->toBe($second->heat)
        ->and($first->toBreakdown())->toBe($second->toBreakdown());
});

it('scores between 0 and 100 and says what it weighed', function (): void {
    $team = Team::factory()->create();

    $score = signalScorer()->score(scorableSignal($team));

    expect($score->heat)->toBeGreaterThanOrEqual(0)
        ->and($score->heat)->toBeLessThanOrEqual(100)
        ->and($score->isMeaningful())->toBeTrue()
        // Every component is in the breakdown, judged or not: a panel that silently omitted the
        // ones it could not judge would read as a shorter list rather than as missing evidence.
        ->and($score->components)->toHaveCount(count(SignalScoreComponent::cases()));
});

// --- Each component moves the total the right way ----------------------------------------------

it('rates a more relevant event higher', function (): void {
    $team = Team::factory()->create();

    $cold = signalScorer()->score(scorableSignal($team, ['relevance' => 10]))->heat;
    $hot = signalScorer()->score(scorableSignal($team, ['relevance' => 95]))->heat;

    expect($hot)->toBeGreaterThan($cold);
});

it('rates a more confident classification higher', function (): void {
    $team = Team::factory()->create();

    expect(signalScorer()->score(scorableSignal($team, ['confidence' => 95]))->heat)
        ->toBeGreaterThan(signalScorer()->score(scorableSignal($team, ['confidence' => 20]))->heat);
});

it('decays an older event, halving on the configured half-life', function (): void {
    config()->set('signals.scoring.recency_half_life_days', 7);

    $team = Team::factory()->create();

    $today = componentOf(signalScorer()->score(scorableSignal($team, ['published_at' => now()])), SignalScoreComponent::Recency);
    $week = componentOf(signalScorer()->score(scorableSignal($team, ['published_at' => now()->subDays(7)])), SignalScoreComponent::Recency);
    $fortnight = componentOf(signalScorer()->score(scorableSignal($team, ['published_at' => now()->subDays(14)])), SignalScoreComponent::Recency);

    // An exponential, not a cliff: an event does not stop mattering on a particular morning.
    expect($today->value)->toBe(100)
        ->and($week->value)->toBe(50)
        ->and($fortnight->value)->toBe(25);
});

it('reorders two otherwise identical signals by recency alone', function (): void {
    $team = Team::factory()->create();

    $fresh = signalScorer()->score(scorableSignal($team, ['published_at' => now()]))->heat;
    $stale = signalScorer()->score(scorableSignal($team, ['published_at' => now()->subDays(30)]))->heat;

    // The feed ordered by `published_at` for exactly this reason; the scorer makes it explicit and
    // lets it be traded off against the rest rather than dominating.
    expect($fresh)->toBeGreaterThan($stale);
});

it('never scores a future publication date above full marks', function (): void {
    $team = Team::factory()->create();

    // Provider dates are best-effort ("3 days ago", S2), so a clock skew must not invent a
    // hotter-than-possible signal.
    $component = componentOf(
        signalScorer()->score(scorableSignal($team, ['published_at' => now()->addDays(3)])),
        SignalScoreComponent::Recency,
    );

    expect($component->value)->toBe(100);
});

it('rates a founder above a department head', function (): void {
    $team = Team::factory()->create();

    $founder = componentOf(signalScorer()->score(scorableSignal($team, ['subject_person_role' => 'Founder'])), SignalScoreComponent::Seniority);
    $chief = componentOf(signalScorer()->score(scorableSignal($team, ['subject_person_role' => 'Chief Financial Officer'])), SignalScoreComponent::Seniority);
    $head = componentOf(signalScorer()->score(scorableSignal($team, ['subject_person_role' => 'Head of Partnerships'])), SignalScoreComponent::Seniority);
    $other = componentOf(signalScorer()->score(scorableSignal($team, ['subject_person_role' => 'Site foreman'])), SignalScoreComponent::Seniority);

    expect($founder->value)->toBeGreaterThan($chief->value)
        ->and($chief->value)->toBeGreaterThanOrEqual($head->value)
        ->and($head->value)->toBeGreaterThan($other->value);
});

it('rates a widely reported story above a single-outlet one', function (): void {
    config()->set('signals.scoring.breadth_saturation', 6);

    $team = Team::factory()->create();
    $signal = scorableSignal($team);

    $alone = componentOf(signalScorer()->score($signal), SignalScoreComponent::ClusterBreadth);
    $six = componentOf(
        signalScorer()->score($signal, null, new SignalCluster(['sources_count' => 6])),
        SignalScoreComponent::ClusterBreadth,
    );

    // A signal with no cluster is **one** source, which is a judgement rather than an absence — so
    // breadth does not abstain for an unclustered signal.
    expect($alone->value)->toBeLessThan($six->value)
        ->and($six->value)->toBe(100)
        ->and($alone->isAssessed())->toBeTrue();
});

it('saturates breadth rather than rewarding syndication for ever', function (): void {
    config()->set('signals.scoring.breadth_saturation', 6);

    $team = Team::factory()->create();

    $six = componentOf(signalScorer()->score(scorableSignal($team), null, new SignalCluster(['sources_count' => 6])), SignalScoreComponent::ClusterBreadth);
    $sixteen = componentOf(signalScorer()->score(scorableSignal($team), null, new SignalCluster(['sources_count' => 16])), SignalScoreComponent::ClusterBreadth);

    // The difference between one outlet and six says something about whether the event is real.
    // The difference between six and sixteen says something about syndication.
    expect($sixteen->value)->toBe($six->value);
});

// --- Missing data is not a failure --------------------------------------------------------------

it('abstains on an amount it has no history to rank against', function (): void {
    $team = Team::factory()->create();

    $component = componentOf(signalScorer()->score(scorableSignal($team)), SignalScoreComponent::Amount);

    // A fresh team has seen no deals, so there is no distribution to rank against. Four data
    // points are not a history, and ranking against them would be a number with no meaning.
    expect($component->isAssessed())->toBeFalse()
        ->and($component->contribution)->toBe(0)
        ->and($component->evidence)->toContain('history');
});

it('distinguishes "no amount given" from "not enough history"', function (): void {
    $team = Team::factory()->create();

    // The two read very differently to a member: one says the article was thin, the other says
    // Alima has not seen enough yet.
    $none = componentOf(signalScorer()->score(scorableSignal($team, ['amount_minor' => null, 'currency' => null])), SignalScoreComponent::Amount);

    expect($none->evidence)->toContain('No amount');
});

it('ranks an amount against the team’s own history once it has one', function (): void {
    config()->set('signals.scoring.amount_history_minimum', 5);

    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, function () use ($team): void {
        foreach ([1, 2, 3, 4, 5] as $millions) {
            Signal::factory()->create([
                'team_id' => $team->getKey(),
                'amount_minor' => $millions * 1_000_000_00,
                'currency' => Currency::Usd,
            ]);
        }
    });

    $small = componentOf(signalScorer()->score(scorableSignal($team, ['amount_minor' => 1_000_000_00])), SignalScoreComponent::Amount);
    $large = componentOf(signalScorer()->score(scorableSignal($team, ['amount_minor' => 50_000_000_00])), SignalScoreComponent::Amount);

    // A £2m exit is the biggest thing one team has seen all year and a rounding error to another,
    // so the component ranks rather than measures.
    expect($small->isAssessed())->toBeTrue()
        ->and($large->value)->toBe(100)
        ->and($small->value)->toBeLessThan($large->value);
});

it('never ranks one currency against another', function (): void {
    config()->set('signals.scoring.amount_history_minimum', 3);

    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, function () use ($team): void {
        foreach ([1, 2, 3, 4] as $millions) {
            Signal::factory()->create([
                'team_id' => $team->getKey(),
                'amount_minor' => $millions * 1_000_000_00,
                'currency' => Currency::Usd,
            ]);
        }
    });

    // Ranking 40,000,000 AED against 2,000,000 USD would rank the exchange rate.
    $component = componentOf(
        signalScorer()->score(scorableSignal($team, ['amount_minor' => 40_000_000_00, 'currency' => Currency::Aed])),
        SignalScoreComponent::Amount,
    );

    expect($component->isAssessed())->toBeFalse();
});

it('abstains on an outlet it has no history with', function (): void {
    $team = Team::factory()->create();

    $component = componentOf(signalScorer()->score(scorableSignal($team)), SignalScoreComponent::SourceAuthority);

    expect($component->isAssessed())->toBeFalse()
        ->and($component->evidence)->toContain('history');
});

it('rates an outlet the team keeps above one it dismisses', function (): void {
    config()->set('signals.scoring.source_history_minimum', 5);

    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, function () use ($team): void {
        SignalSourceStat::factory()->trusted()->create([
            'team_id' => $team->getKey(),
            'source_key' => 'gulf-business',
            'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
        ]);
        SignalSourceStat::factory()->distrusted()->create([
            'team_id' => $team->getKey(),
            'source_key' => 'rumour-mill',
            'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
        ]);
    });

    $good = componentOf(signalScorer()->score(scorableSignal($team, ['source_name' => 'Gulf Business'])), SignalScoreComponent::SourceAuthority);
    $bad = componentOf(signalScorer()->score(scorableSignal($team, ['source_name' => 'Rumour Mill'])), SignalScoreComponent::SourceAuthority);

    expect($good->isAssessed())->toBeTrue()
        ->and($bad->isAssessed())->toBeTrue()
        ->and($good->value)->toBeGreaterThan($bad->value);
});

it('judges an outlet per event type, not in general', function (): void {
    config()->set('signals.scoring.source_history_minimum', 5);

    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, function () use ($team): void {
        SignalSourceStat::factory()->trusted()->create([
            'team_id' => $team->getKey(),
            'source_key' => 'gulf-business',
            'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
        ]);
    });

    // Gulf Business is excellent on funding rounds and says nothing useful about relocations. One
    // authority figure per outlet would average those into a number wrong about both.
    $known = componentOf(signalScorer()->score(scorableSignal($team)), SignalScoreComponent::SourceAuthority);
    $unknown = componentOf(
        signalScorer()->score(scorableSignal($team, ['event_key' => SignalPresetCatalogue::RELOCATION])),
        SignalScoreComponent::SourceAuthority,
    );

    expect($known->isAssessed())->toBeTrue()
        ->and($unknown->isAssessed())->toBeFalse();
});

it('does not score a thinly reported event down for being thin', function (): void {
    $team = Team::factory()->create();

    // Everything unjudgeable removed: no amount, no outlet, no role, no ICP. The heat must come
    // from what *is* known rather than being dragged down by absences, which is the rule
    // `DeterministicScorer` documents and this follows.
    $thin = signalScorer()->score(scorableSignal($team, [
        'amount_minor' => null,
        'currency' => null,
        'source_name' => null,
        'subject_person_role' => null,
        'relevance' => 90,
        'confidence' => 90,
    ]));

    $full = signalScorer()->score(scorableSignal($team, ['relevance' => 90, 'confidence' => 90]));

    expect($thin->heat)->toBeGreaterThan(70)
        // And it says which components it could not weigh, so the card can show the absences.
        ->and($thin->assessed)->toBeLessThan($full->assessed);
});

// --- ICP fit reuses Scoring's own criteria match ------------------------------------------------

it('abstains on ICP fit when the team has no active profile', function (): void {
    $team = Team::factory()->create();

    $component = componentOf(signalScorer()->score(scorableSignal($team)), SignalScoreComponent::IcpFit);

    expect($component->isAssessed())->toBeFalse()
        ->and($component->evidence)->toContain('ideal customer');
});

it('scores ICP fit through Scoring’s criteria, matching on geography and seniority', function (): void {
    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, function () use ($team): void {
        $icp = Icp::factory()->create(['team_id' => $team->getKey(), 'is_active' => true, 'name' => 'Dubai founders']);

        IcpCriterion::factory()->create([
            'team_id' => $team->getKey(),
            'icp_id' => $icp->getKey(),
            'type' => CriterionType::Geography,
            'value' => 'Dubai',
        ]);
        IcpCriterion::factory()->create([
            'team_id' => $team->getKey(),
            'icp_id' => $icp->getKey(),
            'type' => CriterionType::Seniority,
            'value' => 'Founder',
        ]);
    });

    $matching = componentOf(signalScorer()->score(scorableSignal($team)), SignalScoreComponent::IcpFit);
    $elsewhere = componentOf(
        signalScorer()->score(scorableSignal($team, ['location' => 'Manchester', 'jurisdiction' => 'GB', 'subject_person_role' => 'Site foreman'])),
        SignalScoreComponent::IcpFit,
    );

    // The same criteria the lead scores use, applied to what the event is about — not a second fit
    // score with its own idea of what "Dubai" means.
    expect($matching->isAssessed())->toBeTrue()
        ->and($matching->value)->toBe(100)
        ->and($elsewhere->value)->toBe(0);
});

it('scores an ICP exclusion as nought rather than abstaining', function (): void {
    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, function () use ($team): void {
        $icp = Icp::factory()->create(['team_id' => $team->getKey(), 'is_active' => true]);

        IcpCriterion::factory()->create([
            'team_id' => $team->getKey(),
            'icp_id' => $icp->getKey(),
            'type' => CriterionType::Exclusion,
            'value' => 'Acme',
        ]);
    });

    // "Your own profile rules this out" is a judgement, not an absence of one.
    $component = componentOf(signalScorer()->score(scorableSignal($team)), SignalScoreComponent::IcpFit);

    expect($component->isAssessed())->toBeTrue()
        ->and($component->value)->toBe(0);
});

// --- Weights -----------------------------------------------------------------------------------

it('is normalised, so zeroing a weight does not re-scale the feed', function (): void {
    $team = Team::factory()->create();
    $signal = scorableSignal($team, ['relevance' => 100, 'confidence' => 100, 'published_at' => now()]);

    $before = signalScorer()->score($signal)->heat;

    // Heat is `contributed / assessed`, so dropping a component changes which questions were asked
    // and not the scale the answer is on.
    config()->set('signals.scoring.weights.seniority', 0);
    $after = signalScorer()->score($signal)->heat;

    expect($before)->toBeGreaterThan(80)->and($after)->toBeGreaterThan(80);
});

it('lets a definition override a weight', function (): void {
    $team = Team::factory()->create();

    $definition = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => SignalPresetCatalogue::FUNDING_ROUND,
        // Deal size means everything for this type and nothing for a relocation, which is why the
        // override is on the definition rather than on the watch.
        'score_weights' => ['amount' => 90, 'recency' => 1],
    ]));

    $component = componentOf(signalScorer()->score(scorableSignal($team), $definition), SignalScoreComponent::Amount);
    $recency = componentOf(signalScorer()->score(scorableSignal($team), $definition), SignalScoreComponent::Recency);

    expect($component->weight)->toBe(90)->and($recency->weight)->toBe(1);
});

it('reports a meaningless score as meaningless rather than as cold', function (): void {
    $team = Team::factory()->create();

    // Every weight zeroed: nothing was weighed, so the heat is 0 **and means nothing**. A caller
    // showing that as "we judged this and it is worthless" would be the one lie `SignalScore`
    // exists to prevent.
    foreach (SignalScoreComponent::values() as $component) {
        config()->set('signals.scoring.weights.'.$component, 0);
    }

    $score = signalScorer()->score(scorableSignal($team));

    expect($score->heat)->toBe(0)->and($score->isMeaningful())->toBeFalse();
});

// --- The stored breakdown ----------------------------------------------------------------------

it('round-trips its breakdown through storage', function (): void {
    $team = Team::factory()->create();

    $score = signalScorer()->score(scorableSignal($team));
    $read = SignalScore::componentsFrom($score->toBreakdown());

    expect($read)->toHaveCount(count($score->components))
        ->and($read[0]->component)->toBe($score->components[0]->component)
        ->and($read[0]->value)->toBe($score->components[0]->value);
});

it('ignores a component it no longer recognises when reading a stored breakdown', function (): void {
    // A component removed in a later release must not make every signal scored before it
    // unreadable — a card rendering seven rows instead of eight beats one that throws.
    $read = SignalScore::componentsFrom([
        ['component' => 'relevance', 'value' => 70, 'weight' => 30, 'contribution' => 20],
        ['component' => 'moon_phase', 'value' => 99, 'weight' => 99, 'contribution' => 99],
        'not even an array',
    ]);

    expect($read)->toHaveCount(1)
        ->and($read[0]->component)->toBe(SignalScoreComponent::Relevance);
});

// --- Tenancy -----------------------------------------------------------------------------------

it('never ranks one team’s amounts against another team’s history', function (): void {
    config()->set('signals.scoring.amount_history_minimum', 3);

    $rich = Team::factory()->create();
    $new = Team::factory()->create();

    app(TeamContext::class)->run($rich, function () use ($rich): void {
        foreach ([100, 200, 300, 400] as $millions) {
            Signal::factory()->create([
                'team_id' => $rich->getKey(),
                'amount_minor' => $millions * 1_000_000_00,
                'currency' => Currency::Usd,
            ]);
        }
    });

    // The new team has seen nothing, so it abstains — rather than having its first $40m deal
    // ranked as small against somebody else's portfolio.
    $component = componentOf(signalScorer()->score(scorableSignal($new)), SignalScoreComponent::Amount);

    expect($component->isAssessed())->toBeFalse();
});

it('never reads another team’s opinion of an outlet', function (): void {
    config()->set('signals.scoring.source_history_minimum', 5);

    $trusting = Team::factory()->create();
    $other = Team::factory()->create();

    app(TeamContext::class)->run($trusting, function () use ($trusting): void {
        SignalSourceStat::factory()->trusted()->create([
            'team_id' => $trusting->getKey(),
            'source_key' => 'gulf-business',
            'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
        ]);
    });

    $component = componentOf(signalScorer()->score(scorableSignal($other)), SignalScoreComponent::SourceAuthority);

    expect($component->isAssessed())->toBeFalse();
});
