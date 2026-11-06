<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Contracts\LeadSignalSource;
use App\Modules\Scoring\Enums\CriterionMatch;
use App\Modules\Scoring\Services\DeterministicScorer;
use App\Modules\Scoring\Services\NoLeadSignals;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Services\MatchedLeadSignals;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Lead quality Q3: an ICP's buying-signal criteria are judged by the rules, against the signals
 * Signals has already matched to the lead, instead of abstaining unconditionally.
 *
 * `icpWith`, `scorableLead` and `rowFor` come from DeterministicScorerTest.
 */
function signalAbout(Team $team, Lead $lead, array $attributes = []): Signal
{
    return app(TeamContext::class)->run($team, fn (): Signal => Signal::factory()->create([
        'team_id' => $team->getKey(),
        'matched_lead_id' => $lead->getKey(),
        'headline' => 'Acme closes a funding round of $4m',
        'published_at' => now()->subDays(3),
        ...$attributes,
    ]));
}

function scoreWith(Team $team, Lead $lead, $icp)
{
    return app(TeamContext::class)->run($team, fn () => app(DeterministicScorer::class)->score($lead, $icp));
}

it('binds the permissive source by default and the real one once Signals is loaded', function (): void {
    // Scoring binds `NoLeadSignals`; `SignalsServiceProvider` registers after it and replaces it.
    // Both halves matter — the default is what keeps a build without Signals grading as before.
    expect(app(LeadSignalSource::class))->toBeInstanceOf(MatchedLeadSignals::class)
        ->and((new NoLeadSignals)->labelsFor(new Lead))->toBe([]);
});

it('scores a buying signal from a signal matched to the lead', function (): void {
    [$team, $icp] = icpWith([[CriterionType::BuyingSignal, 'funding round', 60]]);
    $lead = scorableLead($team);

    signalAbout($team, $lead);

    $result = scoreWith($team, $lead, $icp);

    expect($result->score)->toBe(100)
        ->and(rowFor($result->breakdown, CriterionType::BuyingSignal)->match)->toBe(CriterionMatch::Matched);
});

it('abstains when the lead has no signals rather than marking it down', function (): void {
    [$team, $icp] = icpWith([
        [CriterionType::BuyingSignal, 'funding round', 60],
        [CriterionType::Industry, 'Logistics', 40],
    ]);
    $lead = scorableLead($team, ['industry' => 'Logistics']);

    $result = scoreWith($team, $lead, $icp);
    $row = rowFor($result->breakdown, CriterionType::BuyingSignal);

    // The buying signal's weight leaves BOTH sides of the ratio, so the industry match alone makes
    // 100 — not 40. A lead is never marked down for what we simply do not know.
    expect($result->score)->toBe(100)
        ->and($row->match)->toBe(CriterionMatch::Unknown)
        ->and($row->evidence)->toBe('No signals recorded about this lead yet.');
});

it('does not count a signal the team dismissed', function (): void {
    [$team, $icp] = icpWith([[CriterionType::BuyingSignal, 'funding round', 60]]);
    $lead = scorableLead($team);

    app(TeamContext::class)->run($team, fn () => Signal::factory()->dismissed()->create([
        'team_id' => $team->getKey(),
        'matched_lead_id' => $lead->getKey(),
        'headline' => 'Acme closes a funding round of $4m',
        'published_at' => now()->subDays(3),
    ]));

    // A member who threw the event away made a judgement; a rule that still counts it is overruling
    // a person.
    expect(rowFor(scoreWith($team, $lead, $icp)->breakdown, CriterionType::BuyingSignal)->match)
        ->toBe(CriterionMatch::Unknown);
});

it('does not count a signal that fell outside the window', function (): void {
    config()->set('signals.scoring.lead_signal_days', 30);

    [$team, $icp] = icpWith([[CriterionType::BuyingSignal, 'funding round', 60]]);
    $lead = scorableLead($team);

    signalAbout($team, $lead, ['published_at' => now()->subDays(400)]);

    expect(rowFor(scoreWith($team, $lead, $icp)->breakdown, CriterionType::BuyingSignal)->match)
        ->toBe(CriterionMatch::Unknown);
});

it('reads a clustered story once, through the signal the feed shows', function (): void {
    [$team] = icpWith([[CriterionType::BuyingSignal, 'funding round', 60]]);
    $lead = scorableLead($team);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        $cluster = SignalCluster::factory()->create(['team_id' => $team->getKey()]);

        $primary = Signal::factory()->create([
            'team_id' => $team->getKey(),
            'matched_lead_id' => $lead->getKey(),
            'signal_cluster_id' => $cluster->getKey(),
            'headline' => 'Acme closes a funding round of $4m',
            'published_at' => now()->subDays(3),
        ]);

        // Five more outlets carrying the same story, none of which the feed shows.
        Signal::factory()->count(5)->create([
            'team_id' => $team->getKey(),
            'matched_lead_id' => $lead->getKey(),
            'signal_cluster_id' => $cluster->getKey(),
            'headline' => 'Coverage of the same funding round',
            'published_at' => now()->subDays(3),
        ]);

        $cluster->forceFill(['primary_signal_id' => $primary->getKey()])->save();
    });

    $labels = app(TeamContext::class)->run($team, fn () => app(MatchedLeadSignals::class)->labelsFor($lead));

    // One event, one entry — otherwise a well-reported round would outweigh a quiet one for no
    // reason but coverage.
    expect($labels)->toContain('Acme closes a funding round of $4m')
        ->and($labels)->not->toContain('Coverage of the same funding round');
});

it('cannot see another team’s signals', function (): void {
    [$team, $icp] = icpWith([[CriterionType::BuyingSignal, 'funding round', 60]]);
    $lead = scorableLead($team);
    $other = Team::factory()->create();

    signalAbout($other, $lead, ['team_id' => $other->getKey()]);

    expect(rowFor(scoreWith($team, $lead, $icp)->breakdown, CriterionType::BuyingSignal)->match)
        ->toBe(CriterionMatch::Unknown);
});
