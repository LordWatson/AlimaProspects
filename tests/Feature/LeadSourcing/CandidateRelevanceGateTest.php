<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Actions\RecordLeadEvidenceAction;
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\LeadSourcing\Services\CandidateRelevanceGate;
use App\Modules\LeadSourcing\Services\LeadCandidateFilter;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use App\Modules\LeadSourcing\Testing\FakeLeadSourceProvider;
use App\Modules\Onboarding\Models\BusinessProfile;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * The relevance gate (ADR 0035): the judgement word matching cannot make. These run the whole AI
 * Platform pipeline against `FakeAiTransport`, because what matters is what a model's *answer*
 * does to the candidate list — above all what happens when there is no answer.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->seed(AiPromptTemplateSeeder::class);
});

/**
 * @return array{Team, FakeAiTransport}
 */
function bootGate(bool $withProfile = true): array
{
    $team = Team::factory()->create();

    if ($withProfile) {
        app(TeamContext::class)->run($team, fn () => BusinessProfile::factory()->completed()->create([
            'team_id' => $team->getKey(),
            'company_name' => 'Kiln & Co',
            'industry' => 'Graphic design',
        ]));
    }

    $transport = new FakeAiTransport;
    app()->instance(AiTransport::class, $transport);
    app()->forgetInstance(AiClient::class);
    app()->forgetInstance(CandidateRelevanceGate::class);

    return [$team, $transport];
}

function gateCandidate(string $name): SourcedLead
{
    return new SourcedLead(
        source: LeadSource::WebSearch,
        companyName: $name,
        website: 'https://'.Str::slug($name).'.example',
        raw: ['snippet' => 'About '.$name],
    );
}

/**
 * @param  list<SourcedLead>  $leads
 * @return array<string, mixed>
 */
function screenWithGate(Team $team, array $leads): array
{
    return app(TeamContext::class)->run($team, fn (): array => app(CandidateRelevanceGate::class)->screen(
        new DiscoveryQuery(source: LeadSource::WebSearch, terms: 'bakeries', customerDescription: 'Independent bakeries'),
        $leads,
    ));
}

it('drops a confident competitor or misfit and keeps everything it is less sure of', function (): void {
    [$team, $transport] = bootGate();

    $transport->queueStructured(['verdicts' => [
        ['index' => 1, 'verdict' => 'fit', 'confidence' => 90, 'reason' => 'An independent bakery.'],
        // No trade word in its name, which is exactly what word matching cannot catch.
        ['index' => 2, 'verdict' => 'competitor', 'confidence' => 85, 'reason' => 'A packaging design studio.'],
        ['index' => 3, 'verdict' => 'not_fit', 'confidence' => 95, 'reason' => 'A directory, not a business.'],
        // The same verdicts below the confidence floor are kept: a wrongly dropped customer is
        // gone for good, a wrongly kept one is graded and shown.
        ['index' => 4, 'verdict' => 'competitor', 'confidence' => 40, 'reason' => 'Might be a studio.'],
        ['index' => 5, 'verdict' => 'unclear', 'confidence' => 99, 'reason' => 'Only a name to go on.'],
        // A number that was never offered is the model talking, not judging.
        ['index' => 12, 'verdict' => 'not_fit', 'confidence' => 99, 'reason' => 'Nobody.'],
    ]]);

    $result = screenWithGate($team, array_map(gateCandidate(...), [
        'Saltmarsh Sourdough', 'Studio Eight', 'Food Listings', 'Marlowe House', 'Fell and Furrow',
    ]));

    expect(array_map(static fn (SourcedLead $lead): string => $lead->companyName, $result['kept']))
        ->toBe(['Saltmarsh Sourdough', 'Marlowe House', 'Fell and Furrow'])
        ->and($result['rejected'])->toBe([
            ['name' => 'Studio Eight', 'verdict' => 'competitor', 'reason' => 'A packaging design studio.'],
            ['name' => 'Food Listings', 'verdict' => 'not_fit', 'reason' => 'A directory, not a business.'],
        ])
        // Only a `fit` leaves a reason behind; it becomes evidence on the lead.
        ->and($result['reasons'])->toBe(['domain:saltmarsh-sourdough.example' => 'An independent bakery.'])
        ->and($result['judged'])->toBe(5);

    // The candidates went in as data, and the seller they are judged against went with them.
    expect($transport->requests[0]->prompt)
        ->toContain('2. Studio Eight')
        ->toContain('Kiln & Co');
});

it('lets every candidate through unjudged when the AI is unavailable', function (): void {
    [$team, $transport] = bootGate();
    $transport->queueFailure();

    $leads = [gateCandidate('Saltmarsh Sourdough'), gateCandidate('Studio Eight')];
    $result = screenWithGate($team, $leads);

    // Discovery is not an AI feature from the member's side: the gate degrades open.
    expect($result['kept'])->toBe($leads)
        ->and($result['rejected'])->toBe([])
        ->and($result['judged'])->toBe(0);
});

it('does not ask at all without a business profile, or when switched off', function (): void {
    [$team, $transport] = bootGate(withProfile: false);

    expect(screenWithGate($team, [gateCandidate('Studio Eight')])['kept'])->toHaveCount(1);

    [$team, $transport] = bootGate();
    config()->set('lead-sourcing.relevance_gate.enabled', false);

    expect(screenWithGate($team, [gateCandidate('Studio Eight')])['kept'])->toHaveCount(1)
        ->and($transport->requests)->toBeEmpty();
});

it('judges against the running team only, never another team’s profile or ICP', function (): void {
    [$team, $transport] = bootGate();

    $other = Team::factory()->create();
    $otherIcp = app(TeamContext::class)->run($other, function () use ($other): Icp {
        BusinessProfile::factory()->completed()->create(['team_id' => $other->getKey(), 'company_name' => 'Rival Holdings']);

        return Icp::query()->create(['team_id' => $other->getKey(), 'name' => 'Rival secret profile', 'is_active' => true]);
    });

    $transport->queueStructured(['verdicts' => []]);

    // A query that names the other team's ICP, run in this team's context.
    app(TeamContext::class)->run($team, fn (): array => app(CandidateRelevanceGate::class)->screen(
        new DiscoveryQuery(source: LeadSource::WebSearch, terms: 'bakeries', icpId: (int) $otherIcp->getKey()),
        [gateCandidate('Saltmarsh Sourdough')],
    ));

    expect($transport->requests[0]->prompt)
        ->toContain('Kiln & Co')
        ->not->toContain('Rival Holdings')
        ->not->toContain('Rival secret profile');

    // And with no team in context at all there is nobody to judge for: nothing is sent.
    app(TeamContext::class)->forget();
    app(CandidateRelevanceGate::class)->screen(
        new DiscoveryQuery(source: LeadSource::WebSearch, terms: 'bakeries'),
        [gateCandidate('Saltmarsh Sourdough')],
    );

    expect($transport->callCount())->toBe(1);
});

it('judges no more candidates than its call budget, and passes the rest', function (): void {
    [$team, $transport] = bootGate();

    config()->set('lead-sourcing.relevance_gate.batch_size', 2);
    config()->set('lead-sourcing.relevance_gate.max_calls', 1);

    $transport->queueStructured(['verdicts' => [
        ['index' => 1, 'verdict' => 'competitor', 'confidence' => 90, 'reason' => 'Studio.'],
        ['index' => 2, 'verdict' => 'fit', 'confidence' => 90, 'reason' => 'Bakery.'],
    ]]);

    $result = screenWithGate($team, array_map(gateCandidate(...), ['Studio Eight', 'Saltmarsh', 'Unjudged One', 'Unjudged Two']));

    expect($transport->callCount())->toBe(1)
        ->and(array_map(static fn (SourcedLead $lead): string => $lead->companyName, $result['kept']))
        ->toBe(['Saltmarsh', 'Unjudged One', 'Unjudged Two']);
});

it('keeps the reason on the lead and what it refused on the run', function (): void {
    [$team, $transport] = bootGate();

    app(LeadSourceProviderRegistry::class)->register(
        (new FakeLeadSourceProvider(LeadSource::WebSearch))->willReturn([
            gateCandidate('Saltmarsh Sourdough'),
            gateCandidate('Studio Eight'),
        ]),
    );

    $transport->queueStructured(['verdicts' => [
        ['index' => 1, 'verdict' => 'fit', 'confidence' => 90, 'reason' => 'An independent bakery selling packaged bread.'],
        ['index' => 2, 'verdict' => 'competitor', 'confidence' => 90, 'reason' => 'A packaging design studio.'],
    ]]);

    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::WebSearch,
        'requested_limit' => 25,
        'params' => (new DiscoveryQuery(source: LeadSource::WebSearch, terms: 'bakeries'))->toParams(),
    ]));

    (new DiscoverLeadsJob((int) $team->getKey(), (int) $run->getKey()))->handle(
        app(LeadSourceProviderRegistry::class),
        app(TeamContext::class),
        app(LeadCandidateFilter::class),
        app(CandidateRelevanceGate::class),
        app(RecordLeadEvidenceAction::class),
    );

    $run->refresh();
    $lead = Lead::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->sole();
    $evidence = LeadEvidence::query()->withoutGlobalScopes()
        ->where('lead_id', $lead->getKey())
        ->where('source', CandidateRelevanceGate::EVIDENCE_SOURCE)
        ->sole();

    expect($run->status)->toBe(DiscoveryRunStatus::Completed)
        // The competitor never became a lead, so it was never enriched or counted.
        ->and($lead->company_name)->toBe('Saltmarsh Sourdough')
        ->and($run->leads_found)->toBe(1)
        ->and($run->leads_rejected)->toBe(1)
        ->and($run->rejections)->toBe([
            ['name' => 'Studio Eight', 'verdict' => 'competitor', 'reason' => 'A packaging design studio.'],
        ])
        // Why this company is here, in the panel that already answers that (ADR 0028).
        ->and($evidence->kind)->toBe(EvidenceKind::AiInference)
        ->and($evidence->summary)->toBe('An independent bakery selling packaged bread.');
});
