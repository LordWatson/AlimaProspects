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
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Services\AiLeadSourceProvider;
use App\Modules\LeadSourcing\Services\LeadCandidateFilter;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * The AI lead source (Phase 7c): the one provider with no index behind it. The whole AI Platform
 * pipeline runs here against `FakeAiTransport` — registry, rendering, schema validation, cost
 * logging — because what matters is not that a mock was called but that a *model's answer* becomes
 * rows that the scorer can actually judge.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->seed(AiPromptTemplateSeeder::class);
});

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

it('turns the model’s companies into leads carrying the fields a score is made of', function (): void {
    [$team, $transport] = bootAiSource();

    $transport->queueStructured([
        'companies' => [
            [
                'company_name' => 'Northern Rooms',
                'industry' => 'Boutique hotel',
                'employee_range' => '11-50',
                'website' => 'northernrooms.co.uk',
                'locality' => 'Manchester',
                'region' => 'Greater Manchester',
                'country' => 'GB',
                'why' => 'A four-site independent hotel group that rebrands regularly.',
            ],
        ],
    ]);

    $leads = discoverWithAi($team, aiQuery());

    expect($leads)->toHaveCount(1);

    $lead = $leads[0];

    expect($lead->source)->toBe(LeadSource::Ai)
        ->and($lead->companyName)->toBe('Northern Rooms')
        // The two fields no index-backed provider fills, and the reason this source exists.
        ->and($lead->industry)->toBe('Boutique hotel')
        ->and($lead->employeeRange)->toBe('11-50')
        ->and($lead->locality)->toBe('Manchester')
        ->and($lead->country)->toBe('GB')
        // A bare domain gets a scheme, so the lead keys on its DOMAIN and collapses with the
        // web-search or Places record of the same company instead of becoming a second row.
        ->and($lead->website)->toBe('https://northernrooms.co.uk')
        ->and($lead->dedupeKey())->toBe('domain:northernrooms.co.uk')
        // Provenance: the claim, and the prompt version of the model that made it.
        ->and($lead->raw['claim']['why'])->toBe('A four-site independent hotel group that rebrands regularly.')
        ->and($lead->raw['prompt_version'])->toBe(1);
});

it('drops what it cannot trust instead of storing a plausible-looking value', function (): void {
    [$team, $transport] = bootAiSource();

    $transport->queueStructured([
        'companies' => [
            // A country name rather than a code: truncated to the column it is going into, this
            // would have been stored as "UN" and read as a country by anyone looking at the row.
            ['company_name' => 'Acme Hotels', 'industry' => 'Hotel', 'country' => 'United Kingdom'],
            // A sentence where a domain belongs.
            ['company_name' => 'Beta Rooms', 'industry' => 'Hotel', 'website' => 'their site is down'],
            // Broken JSON bled into the headcount field — the exact live failure. It is a valid
            // string, so schema validation passes it, but it is not a count and (at 70+ chars) would
            // overflow the 64-char column and fail the run. Dropped to null; the company is kept.
            ['company_name' => 'Gamma Rooms', 'industry' => 'Hotel', 'employee_range' => "51-200','locality':'London'}]}\n```\nI need to correct that output."],
            // No name at all — nothing to be a lead.
            ['company_name' => '   ', 'industry' => 'Hotel'],
        ],
    ]);

    $leads = discoverWithAi($team, aiQuery());

    expect($leads)->toHaveCount(3)
        ->and($leads[0]->country)->toBeNull()
        ->and($leads[1]->website)->toBeNull()
        ->and($leads[2]->companyName)->toBe('Gamma Rooms')
        ->and($leads[2]->employeeRange)->toBeNull();
});

it('keeps a real headcount band but never one wider than its column', function (): void {
    // The provider nulls a band that isn't a count; the DTO is the storage backstop for anything a
    // provider still lets through — `leads.employee_range` is VARCHAR(64), not the 255 other text
    // columns are, and overflowing it is what failed the live run.
    $kept = new SourcedLead(source: LeadSource::Ai, companyName: 'Acme', employeeRange: '1,000-5,000');
    $clamped = new SourcedLead(source: LeadSource::Ai, companyName: 'Acme', employeeRange: str_repeat('9', 300));

    expect($kept->toLeadAttributes()['employee_range'])->toBe('1,000-5,000')
        ->and(mb_strlen((string) $clamped->toLeadAttributes()['employee_range']))->toBe(64);
});

it('collapses the same company named twice in one answer', function (): void {
    [$team, $transport] = bootAiSource();

    $transport->queueStructured([
        'companies' => [
            ['company_name' => 'Northern Rooms', 'industry' => 'Hotel', 'website' => 'https://northernrooms.co.uk'],
            ['company_name' => 'Northern Rooms Ltd', 'industry' => 'Hotel', 'website' => 'northernrooms.co.uk/about'],
        ],
    ]);

    expect(discoverWithAi($team, aiQuery()))->toHaveCount(1);
});

it('never asks for more companies than the output ceiling allows', function (): void {
    [$team, $transport] = bootAiSource();

    config()->set('lead-sourcing.providers.ai.max_results', 3);

    $transport->queueStructured([
        'companies' => array_map(
            static fn (int $i): array => ['company_name' => "Hotel {$i}", 'industry' => 'Hotel'],
            range(1, 10),
        ),
    ]);

    // A run asking for 25 is clamped: the count here is an output-token cost, not a request count.
    expect(discoverWithAi($team, aiQuery(25)))->toHaveCount(3)
        ->and($transport->requests[0]->prompt)->toContain('up to 3');
});

it('asks nothing of the model when there is nothing to search with', function (): void {
    [$team, $transport] = bootAiSource();

    $query = new DiscoveryQuery(source: LeadSource::Ai, terms: '  ');

    expect(discoverWithAi($team, $query))->toBe([])
        ->and($transport->requests)->toBeEmpty();
});

it('charges the run nothing, because the AI ledger already holds the cost', function (): void {
    // Metering it here as well would count one request twice in the platform margin: `ai_requests`
    // is where `PlatformRollup` reads Claude spend from.
    expect(app(AiLeadSourceProvider::class)->costMicros(10))->toBe(0);
});

it('fails the run with its own message when the AI allowance is spent', function (): void {
    [$team] = bootAiSource();

    app()->instance(AiClient::class, new class implements AiClient
    {
        public function structured(string $key, array $input = [], ?int $version = null): StructuredAiResponse
        {
            throw AiCreditExceededException::make();
        }
    });

    $provider = new AiLeadSourceProvider(app(AiClient::class));

    try {
        app(TeamContext::class)->run($team, fn () => $provider->discover(aiQuery()));
        $this->fail('Expected the discovery to be refused.');
    } catch (LeadSourcingException $e) {
        // Not `quota_exhausted`: that is the lead allowance, fixed by a different thing.
        expect($e->errorCode())->toBe('lead_sourcing.ai_allowance_exhausted')
            // Terminal — a retry would be refused for the same reason, so it must not burn the
            // job's attempts.
            ->and($e->isRetryable())->toBeFalse();
    }
});

it('fails fast, not three times, when the prompt is not seeded in this database', function (): void {
    // What actually happened on the first live run: the prompt row had not been seeded, every
    // attempt raised `ai.unknown_prompt`, and because it was handled as a provider failure the job
    // spent all three of its tries on a condition no retry can change -- and told the member to
    // try again shortly, which was wrong advice three times over.
    [$team] = bootAiSource();

    $provider = new AiLeadSourceProvider(new class implements AiClient
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

    $provider = new AiLeadSourceProvider(new class implements AiClient
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

it('persists an AI lead with the trade and headcount the scorer reads', function (): void {
    [$team, $transport] = bootAiSource();

    $transport->queueStructured([
        'companies' => [[
            'company_name' => 'Northern Rooms',
            'industry' => 'Boutique hotel',
            'employee_range' => '11-50',
            'website' => 'https://northernrooms.co.uk',
            'country' => 'GB',
        ]],
    ]);

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
    );

    $lead = Lead::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    expect($run->fresh()->status)->toBe(DiscoveryRunStatus::Completed)
        // Zero on the run, not because the call was free but because its cost is on the AI ledger.
        ->and($run->fresh()->cost_micros)->toBe(0)
        ->and($lead->source)->toBe(LeadSource::Ai)
        ->and($lead->industry)->toBe('Boutique hotel')
        ->and($lead->employee_range)->toBe('11-50');
});
