<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Actions\GenerateWhyNowSummaryAction;
use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Jobs\GenerateWhyNowSummaryJob;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\LeadSourcing\Services\LeadConfidence;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * The why-now sentence (PL1).
 *
 * The one assertion that matters most is the boring one: **the model is handed the evidence and
 * nothing else**. It is the only machine-checkable half of "no invented facts" — the other half is
 * that the UI always shows the raw evidence beside the sentence, which is a layout decision this
 * suite cannot make — so it is asserted on the prompt input directly rather than on the answer.
 */

beforeEach(function (): void {
    // Faked so the provenance observer's own dispatch does not run the summary job inline and
    // consume a response this test has not queued yet. Every test here invokes the Action or the
    // job itself, which is the point: the gate is what is under test, not the dispatch.
    Queue::fake();

    $this->seed(AiPromptTemplateSeeder::class);

    $this->transport = new FakeAiTransport;
    $this->app->instance(AiTransport::class, $this->transport);
});

/**
 * A lead with one filing and one news story, in the team's context.
 *
 * @return array{Team, Lead}
 */
function leadWithEvidence(): array
{
    $team = Team::factory()->create();

    $lead = app(TeamContext::class)->run($team, function () use ($team): Lead {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::CompaniesHouse,
            'company_name' => 'Zephyr Logistics Ltd',
            'industry' => 'Freight forwarding',
            'locality' => 'Manchester',
        ]);

        LeadEvidence::factory()
            ->signal('news', Carbon::parse('2026-10-12'))
            ->create(['team_id' => $team->getKey(), 'lead_id' => $lead->getKey()]);

        return $lead->fresh();
    });

    return [$team, $lead];
}

function generateWhyNowFor(Team $team, Lead $lead): ?string
{
    return app(TeamContext::class)->run(
        $team,
        fn (): ?string => app(GenerateWhyNowSummaryAction::class)->handle($lead),
    );
}

it('writes a summary from the evidence and stores the hash it was written from', function (): void {
    [$team, $lead] = leadWithEvidence();

    $this->transport->queueStructured(['summary' => 'Raised a Series A on 12 October.']);

    $summary = generateWhyNowFor($team, $lead);

    $fresh = $lead->fresh();
    $expectedHash = app(TeamContext::class)->run(
        $team,
        fn (): ?string => app(LeadConfidence::class)->for($fresh)->evidenceHash,
    );

    expect($summary)->toBe('Raised a Series A on 12 October.')
        ->and($fresh->why_now_summary)->toBe('Raised a Series A on 12 October.')
        ->and($fresh->why_now_generated_at)->not->toBeNull()
        // Written *with* the sentence: a hash stamped before the call would mark the lead as done
        // even when the call failed, and the summary would never arrive.
        ->and($fresh->why_now_evidence_hash)->toBe($expectedHash);
});

it('hands the model the supplied evidence and no other fact about the lead', function (): void {
    [$team, $lead] = leadWithEvidence();

    $this->transport->queueStructured(['summary' => 'Something.']);

    generateWhyNowFor($team, $lead);

    $prompt = $this->transport->lastRequest()?->prompt;

    // The evidence is there, with its date and its outlet.
    expect($prompt)->toContain('Raises Series A funding')
        ->and($prompt)->toContain('Example Wire')
        // And the company's own name and profile, which the sentence is allowed to lean on.
        ->and($prompt)->toContain('Zephyr Logistics Ltd')
        ->and($prompt)->toContain('Freight forwarding')
        // But nothing the prompt was not given: no dedupe key, no internal ids, no raw payload.
        ->and($prompt)->not->toContain($lead->dedupe_key)
        ->and($prompt)->not->toContain('"signal_id"');
});

it('skips the call entirely when the evidence has not changed', function (): void {
    [$team, $lead] = leadWithEvidence();

    $this->transport->queueStructured(['summary' => 'First pass.']);
    generateWhyNowFor($team, $lead);

    $before = $this->transport->callCount();

    // The job is the gate, not the Action: it re-reads the hash before spending anything, because
    // by the time a worker picks it up another job may have written the same sentence.
    (new GenerateWhyNowSummaryJob((int) $team->getKey(), (int) $lead->getKey()))->handle(
        app(GenerateWhyNowSummaryAction::class),
        app(TeamContext::class),
        app(LeadConfidence::class),
    );

    expect($this->transport->callCount())->toBe($before)
        ->and($lead->fresh()->why_now_summary)->toBe('First pass.');
});

it('regenerates once a reason is added', function (): void {
    [$team, $lead] = leadWithEvidence();

    $this->transport->queueStructured(['summary' => 'First pass.']);
    generateWhyNowFor($team, $lead);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        LeadEvidence::factory()
            ->signal('web_search')
            ->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'title' => 'Opened a second depot',
                'source_url' => 'https://example.test/news/depot',
                'dedupe_hash' => sha1('https://example.test/news/depot'),
            ]);
    });

    $this->transport->queueStructured(['summary' => 'Second pass.']);

    (new GenerateWhyNowSummaryJob((int) $team->getKey(), (int) $lead->getKey()))->handle(
        app(GenerateWhyNowSummaryAction::class),
        app(TeamContext::class),
        app(LeadConfidence::class),
    );

    expect($lead->fresh()->why_now_summary)->toBe('Second pass.');
});

it('leaves a lead with its facts and no sentence when the model cannot answer', function (): void {
    [$team, $lead] = leadWithEvidence();

    $this->transport->queueStructured(['summary' => '   ']);

    $summary = generateWhyNowFor($team, $lead);

    // A blank answer is not a summary. The lead keeps its evidence and its confidence, the screen
    // falls back to the strongest evidence title, and the hash is NOT written — so the next run
    // tries again rather than marking the lead as summarised with nothing to show.
    expect($summary)->toBeNull()
        ->and($lead->fresh()->why_now_summary)->toBeNull()
        ->and($lead->fresh()->why_now_evidence_hash)->toBeNull()
        ->and($lead->fresh()->confidence)->not->toBeNull();
});

it('does not summarise a lead with nothing to summarise', function (): void {
    $team = Team::factory()->create();

    $lead = app(TeamContext::class)->run($team, function () use ($team): Lead {
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);
        $lead->evidence()->delete();

        return $lead->fresh();
    });

    expect(generateWhyNowFor($team, $lead))->toBeNull()
        ->and($this->transport->callCount())->toBe(0);
});

it('never builds a sentence from a reason a member has flagged as wrong', function (): void {
    $team = Team::factory()->create();

    $lead = app(TeamContext::class)->run($team, function () use ($team): Lead {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::CompaniesHouse,
        ]);

        LeadEvidence::factory()
            ->signal('news')
            ->disputed()
            ->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'title' => 'A story about a different company',
                'source_url' => 'https://example.test/news/wrong',
                'dedupe_hash' => sha1('https://example.test/news/wrong'),
            ]);

        return $lead->fresh();
    });

    $this->transport->queueStructured(['summary' => 'Something.']);

    generateWhyNowFor($team, $lead);

    expect($this->transport->lastRequest()?->prompt)->not->toContain('A story about a different company');
});

it('switches off wholesale from config', function (): void {
    config(['evidence.why_now.enabled' => false]);

    [$team, $lead] = leadWithEvidence();

    expect(generateWhyNowFor($team, $lead))->toBeNull()
        ->and($this->transport->callCount())->toBe(0);
});

it('sends at most the configured number of reasons, strongest first', function (): void {
    config(['evidence.why_now.max_evidence_items' => 2]);

    $team = Team::factory()->create();

    $lead = app(TeamContext::class)->run($team, function () use ($team): Lead {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::CompaniesHouse,
        ]);

        foreach ([['weakest', 'ai', 20], ['middling', 'web_search', 45], ['strongest', 'news', 95]] as [$title, $source, $reliability]) {
            LeadEvidence::factory()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'kind' => EvidenceKind::Signal,
                'source' => $source,
                'reliability' => $reliability,
                'title' => $title,
                'observed_at' => Carbon::now(),
                'source_url' => 'https://example.test/'.$title,
                'dedupe_hash' => sha1($title),
            ]);
        }

        return $lead->fresh();
    });

    $this->transport->queueStructured(['summary' => 'Something.']);

    generateWhyNowFor($team, $lead);

    $prompt = $this->transport->lastRequest()?->prompt;

    // The lead's provenance row is in there too, which is why this asserts on exclusion of the
    // weakest rather than on a count: what matters is that the cap bites from the bottom.
    expect($prompt)->toContain('strongest')
        ->and($prompt)->not->toContain('weakest');
});
