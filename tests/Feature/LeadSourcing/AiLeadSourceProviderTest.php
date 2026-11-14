<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Data\StructuredAiResponse;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\AiTransportException;
use App\Modules\AiPlatform\Exceptions\UnknownPromptException;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Actions\RecordLeadEvidenceAction;
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Services\AiLeadSourceProvider;
use App\Modules\LeadSourcing\Services\CandidateRelevanceGate;
use App\Modules\LeadSourcing\Services\DiscoveryPayloadBuilder;
use App\Modules\LeadSourcing\Services\LeadCandidateFilter;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use App\Modules\LeadSourcing\Services\WebSearchProvider;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * The AI lead source (Phase 7c; grounded in real search results since ADR 0035). The whole AI Platform
 * pipeline runs here against `FakeAiTransport` — registry, rendering, schema validation, cost
 * logging — because what matters is not that a mock was called but that a *model's answer* becomes
 * rows that the scorer can actually judge.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->seed(AiPromptTemplateSeeder::class);

    config()->set('lead-sourcing.providers.web_search.api_key', 'test-key');
    config()->set('lead-sourcing.providers.web_search.base_url', 'https://google.serper.dev');
    config()->set('lead-sourcing.providers.web_search.cost_micros_per_request', 3_000);
});

/**
 * The search results the model is given to read. Since ADR 0035 the source has no recall path:
 * what it may name is bounded by what a search actually returned.
 *
 * @param  list<array{title: string, link: string, snippet?: string}>|null  $organic
 */
function fakeSearchResults(?array $organic = null): void
{
    Http::fake(['google.serper.dev/*' => Http::response(['organic' => $organic ?? [
        ['title' => 'Northern Rooms | Boutique hotel in Manchester', 'link' => 'https://northernrooms.co.uk/', 'snippet' => 'A four-site independent hotel group.'],
        ['title' => 'The 10 best boutique hotels in Manchester', 'link' => 'https://citymag.example/best-hotels', 'snippet' => 'Our pick of the best.'],
        ['title' => 'Cotton Mill Hotel', 'link' => 'https://cottonmill.example/rooms', 'snippet' => 'Forty rooms in a converted mill.'],
    ]])]);
}

function bootAiSource(): array
{
    $team = Team::factory()->create();

    $transport = new FakeAiTransport;
    app()->instance(AiTransport::class, $transport);
    app()->forgetInstance(AiClient::class);

    return [$team, $transport];
}

function aiQuery(int $limit = 25): DiscoveryQuery
{
    return new DiscoveryQuery(
        source: LeadSource::Ai,
        terms: 'independent boutique hotels',
        location: 'Manchester',
        limit: $limit,
        customerDescription: 'Hospitality businesses that commission branding work',
        queries: ['boutique hotels'],
    );
}

/**
 * @return list<SourcedLead>
 */
function discoverWithAi(Team $team, DiscoveryQuery $query): array
{
    return app(TeamContext::class)->run(
        $team,
        fn (): array => app(AiLeadSourceProvider::class)->discover($query),
    );
}

function aiSourceWith(AiClient $client): AiLeadSourceProvider
{
    return new AiLeadSourceProvider($client, app(WebSearchProvider::class), app(DiscoveryPayloadBuilder::class));
}

it('names only companies a search result shows, taking identity from the result', function (): void {
    [$team, $transport] = bootAiSource();
    fakeSearchResults();

    $transport->queueStructured(['companies' => [
        [
            'source_index' => 1,
            'company_name' => 'Northern Rooms',
            'industry' => 'Boutique hotel',
            'employee_range' => '11-50',
            'locality' => 'Manchester',
            'country' => 'GB',
            'why' => 'An independent hotel group.',
        ],
        // Cites a result that was never offered: this is the model recalling, and it is not stored.
        ['source_index' => 9, 'company_name' => 'Remembered Hotel Co', 'industry' => 'Hotel'],
    ]]);

    $leads = discoverWithAi($team, aiQuery());

    expect($leads)->toHaveCount(1);

    $lead = $leads[0];

    expect($lead->source)->toBe(LeadSource::Ai)
        ->and($lead->companyName)->toBe('Northern Rooms')
        // The two fields no index-backed provider fills, and the reason this source exists.
        ->and($lead->industry)->toBe('Boutique hotel')
        ->and($lead->employeeRange)->toBe('11-50')
        // The website and the source are the cited result's own, never text the model wrote, so
        // the lead keys on a real domain and collapses with the web-search record of the company.
        ->and($lead->website)->toBe('https://northernrooms.co.uk')
        ->and($lead->sourceUrl)->toBe('https://northernrooms.co.uk/')
        ->and($lead->dedupeKey())->toBe('domain:northernrooms.co.uk')
        // Provenance: the claim, the result it was read from, and the prompt version that read it.
        ->and($lead->raw['claim']['why'])->toBe('An independent hotel group.')
        ->and($lead->raw['result']['title'])->toBe('Northern Rooms | Boutique hotel in Manchester')
        ->and($lead->raw['prompt_version'])->toBe(2);

    // The model was shown the results, numbered, and nothing else to name from.
    expect($transport->requests[0]->prompt)
        ->toContain('1. Northern Rooms | Boutique hotel in Manchester')
        ->toContain('3. Cotton Mill Hotel');
});

it('drops what it cannot trust instead of storing a plausible-looking value', function (): void {
    [$team, $transport] = bootAiSource();
    fakeSearchResults();

    $transport->queueStructured(['companies' => [
        // A country name rather than a code: truncated to its column this would be stored as "UN".
        ['source_index' => 1, 'company_name' => 'Northern Rooms', 'industry' => 'Hotel', 'country' => 'United Kingdom'],
        // Broken JSON bled into the headcount field, a live failure. Dropped to null; the company is kept.
        ['source_index' => 3, 'company_name' => 'Cotton Mill Hotel', 'industry' => 'Hotel', 'employee_range' => '51-200,locality:London}]} I need to correct that.'],
        // No name at all: nothing to be a lead.
        ['source_index' => 2, 'company_name' => '   ', 'industry' => 'Hotel'],
    ]]);

    $leads = discoverWithAi($team, aiQuery());

    expect($leads)->toHaveCount(2)
        ->and($leads[0]->country)->toBeNull()
        ->and($leads[1]->employeeRange)->toBeNull();
});

it('keeps a real headcount band but never one wider than its column', function (): void {
    $kept = new SourcedLead(source: LeadSource::Ai, companyName: 'Acme', employeeRange: '1,000-5,000');
    $clamped = new SourcedLead(source: LeadSource::Ai, companyName: 'Acme', employeeRange: str_repeat('9', 300));

    expect($kept->toLeadAttributes()['employee_range'])->toBe('1,000-5,000')
        ->and(mb_strlen((string) $clamped->toLeadAttributes()['employee_range']))->toBe(64);
});

it('collapses two companies read out of the same result', function (): void {
    [$team, $transport] = bootAiSource();
    fakeSearchResults();

    $transport->queueStructured(['companies' => [
        ['source_index' => 1, 'company_name' => 'Northern Rooms', 'industry' => 'Hotel'],
        ['source_index' => 1, 'company_name' => 'Northern Rooms', 'industry' => 'Hotel group'],
    ]]);

    expect(discoverWithAi($team, aiQuery()))->toHaveCount(1);
});

it('never asks for more companies than the output ceiling allows', function (): void {
    [$team, $transport] = bootAiSource();
    fakeSearchResults();

    config()->set('lead-sourcing.providers.ai.max_results', 1);

    $transport->queueStructured(['companies' => [
        ['source_index' => 1, 'company_name' => 'Northern Rooms', 'industry' => 'Hotel'],
        ['source_index' => 3, 'company_name' => 'Cotton Mill Hotel', 'industry' => 'Hotel'],
    ]]);

    // A run asking for 25 is clamped: the count here is an output-token cost, not a request count.
    expect(discoverWithAi($team, aiQuery(25)))->toHaveCount(1)
        ->and($transport->requests[0]->prompt)->toContain('up to 1');
});

it('asks nothing of the model when there is nothing to search with, or the search finds nothing', function (): void {
    [$team, $transport] = bootAiSource();
    fakeSearchResults([]);

    expect(discoverWithAi($team, new DiscoveryQuery(source: LeadSource::Ai, terms: '  ')))->toBe([])
        // No results means nothing the model is allowed to name, so it is not asked.
        ->and(discoverWithAi($team, aiQuery()))->toBe([])
        ->and($transport->requests)->toBeEmpty();
});

it('charges the run for its searches, and leaves the model cost on the AI ledger', function (): void {
    [$team, $transport] = bootAiSource();
    fakeSearchResults();
    $transport->queueStructured(['companies' => []]);

    $provider = app(AiLeadSourceProvider::class);
    app(TeamContext::class)->run($team, fn (): array => $provider->discover(aiQuery()));

    // One search request. The model call is on `ai_requests`, where `PlatformRollup` reads Claude
    // spend from; metering it here as well would count it twice in the platform margin.
    expect($provider->costMicros(0))->toBe(3_000);
});

it('fails the run with its own message when the AI allowance is spent', function (): void {
    [$team] = bootAiSource();
    fakeSearchResults();

    $provider = aiSourceWith(new class implements AiClient
    {
        public function structured(string $key, array $input = [], ?int $version = null): StructuredAiResponse
        {
            throw AiCreditExceededException::make();
        }
    });

    try {
        app(TeamContext::class)->run($team, fn () => $provider->discover(aiQuery()));
        $this->fail('Expected the discovery to be refused.');
    } catch (LeadSourcingException $e) {
        // Not `quota_exhausted`: that is the lead allowance, fixed by a different thing. Terminal,
        // so it must not burn the job's attempts.
        expect($e->errorCode())->toBe('lead_sourcing.ai_allowance_exhausted')
            ->and($e->isRetryable())->toBeFalse();
    }
});

it('fails fast, not three times, when the prompt is not seeded in this database', function (): void {
    [$team] = bootAiSource();
    fakeSearchResults();

    $provider = aiSourceWith(new class implements AiClient
    {
        public function structured(string $key, array $input = [], ?int $version = null): StructuredAiResponse
        {
            throw UnknownPromptException::for($key);
        }
    });

    try {
        app(TeamContext::class)->run($team, fn () => $provider->discover(aiQuery()));
        $this->fail('Expected the discovery to be refused.');
    } catch (LeadSourcingException $e) {
        expect($e->errorCode())->toBe('lead_sourcing.provider_misconfigured')
            ->and($e->isRetryable())->toBeFalse();
    }
});

it('lets the queue retry a transport failure', function (): void {
    [$team] = bootAiSource();
    fakeSearchResults();

    $provider = aiSourceWith(new class implements AiClient
    {
        public function structured(string $key, array $input = [], ?int $version = null): StructuredAiResponse
        {
            throw AiTransportException::from(new RuntimeException('upstream fell over'));
        }
    });

    try {
        app(TeamContext::class)->run($team, fn () => $provider->discover(aiQuery()));
        $this->fail('Expected the discovery to fail.');
    } catch (LeadSourcingException $e) {
        expect($e->errorCode())->toBe('lead_sourcing.provider_failed')
            ->and($e->isRetryable())->toBeTrue()
            // The message never carries the upstream text: the request contains the team's own
            // description of its customers, and a transport error can quote it back.
            ->and($e->getMessage())->not->toContain('upstream fell over');
    }
});

it('persists a grounded AI lead with the trade the scorer reads and the search spend on the run', function (): void {
    [$team, $transport] = bootAiSource();
    fakeSearchResults();

    $transport->queueStructured(['companies' => [[
        'source_index' => 1,
        'company_name' => 'Northern Rooms',
        'industry' => 'Boutique hotel',
        'employee_range' => '11-50',
        'country' => 'GB',
    ]]]);

    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::Ai,
        'requested_limit' => 25,
        'params' => aiQuery()->toParams(),
    ]));

    (new DiscoverLeadsJob((int) $team->getKey(), (int) $run->getKey()))->handle(
        app(LeadSourceProviderRegistry::class),
        app(TeamContext::class),
        app(LeadCandidateFilter::class),
        app(CandidateRelevanceGate::class),
        app(RecordLeadEvidenceAction::class),
    );

    $lead = Lead::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    expect($run->fresh()->status)->toBe(DiscoveryRunStatus::Completed)
        ->and($run->fresh()->cost_micros)->toBe(3_000)
        ->and($lead->source)->toBe(LeadSource::Ai)
        ->and($lead->industry)->toBe('Boutique hotel')
        ->and($lead->employee_range)->toBe('11-50')
        ->and($lead->website)->toBe('https://northernrooms.co.uk');
});
