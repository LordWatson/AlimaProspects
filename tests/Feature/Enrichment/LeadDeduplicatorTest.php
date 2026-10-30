<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Enrichment\Services\LeadDeduplicator;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dedupe(Team $team, Lead $lead): Lead
{
    return app(TeamContext::class)->run($team, fn (): Lead => app(LeadDeduplicator::class)->deduplicate($lead));
}

function leadCount(Team $team): int
{
    return Lead::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->count();
}

it('merges a domain duplicate into the higher-ranked lead, keeping the best source per field', function (): void {
    $team = Team::factory()->create();

    [$authoritative, $weaker] = app(TeamContext::class)->run($team, function () use ($team): array {
        $a = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::CompaniesHouse,
            'company_number' => '111',
            'company_name' => 'Acme Ltd',
            'website' => 'https://acme.test',
            'industry' => null,
            'dedupe_key' => 'no:111',
        ]);

        $b = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::WebSearch,
            'company_number' => null,
            'company_name' => 'Acme (web title)',
            'website' => 'https://www.acme.test/about',
            'industry' => 'Coffee roasting',
            'dedupe_key' => 'domain:acme.test',
        ]);

        return [$a, $b];
    });

    $survivor = dedupe($team, $weaker);

    expect(leadCount($team))->toBe(1)
        ->and($survivor->getKey())->toBe($authoritative->getKey())
        ->and($survivor->source)->toBe(LeadSource::CompaniesHouse)
        // Higher-ranked name wins; the empty field is filled from the duplicate.
        ->and($survivor->company_name)->toBe('Acme Ltd')
        ->and($survivor->industry)->toBe('Coffee roasting');
});

it('merges a duplicate found by company number', function (): void {
    $team = Team::factory()->create();

    [$authoritative, $weaker] = app(TeamContext::class)->run($team, function () use ($team): array {
        $a = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::CompaniesHouse,
            'company_number' => '111',
            'dedupe_key' => 'no:111',
        ]);

        // Keyed by domain but carries the same registry number — a cross-provider duplicate.
        $b = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::GooglePlaces,
            'company_number' => '111',
            'website' => 'https://acme-directory.test',
            'dedupe_key' => 'domain:acme-directory.test',
        ]);

        return [$a, $b];
    });

    $survivor = dedupe($team, $weaker);

    expect(leadCount($team))->toBe(1)
        ->and($survivor->getKey())->toBe($authoritative->getKey())
        ->and($survivor->source)->toBe(LeadSource::CompaniesHouse);
});

it('merges a duplicate that shares a same-domain contact email and moves its contacts across', function (): void {
    $team = Team::factory()->create();

    [$authoritative] = app(TeamContext::class)->run($team, function () use ($team): array {
        // The authoritative lead carries the website domain; the duplicate is website-less but shares
        // a contact email on that same domain (routable) — the only safe email-match shape.
        $a = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::CompaniesHouse,
            'company_number' => '111',
            'website' => 'https://acme.test',
            'dedupe_key' => 'no:111',
        ]);
        LeadContact::factory()->create(['team_id' => $team->getKey(), 'lead_id' => $a->getKey(), 'email' => 'hello@acme.test']);

        $b = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::WebSearch,
            'company_number' => null,
            'website' => null,
            'dedupe_key' => 'name:web:acme:abc123',
        ]);
        // One shared email (de-duped away) and one unique email (moved to the survivor).
        LeadContact::factory()->create(['team_id' => $team->getKey(), 'lead_id' => $b->getKey(), 'email' => 'hello@acme.test']);
        LeadContact::factory()->create(['team_id' => $team->getKey(), 'lead_id' => $b->getKey(), 'email' => 'jane@acme.test']);

        return [$a, $b];
    });

    // De-duplication is driven from the lead that owns the domain (as the pipeline does).
    $survivor = dedupe($team, $authoritative);

    app(TeamContext::class)->run($team, function () use ($survivor, $authoritative): void {
        expect($survivor->getKey())->toBe($authoritative->getKey())
            ->and($survivor->contacts()->count())->toBe(2) // hello@ (kept) + jane@ (moved)
            ->and(LeadContact::query()->count())->toBe(2);
    });

    expect(leadCount($team))->toBe(1);
});

it('does not merge leads with conflicting company numbers that share a domain', function (): void {
    $team = Team::factory()->create();

    $weaker = app(TeamContext::class)->run($team, function () use ($team): Lead {
        Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::CompaniesHouse,
            'company_number' => '111',
            'website' => 'https://group.test',
            'dedupe_key' => 'no:111',
        ]);

        return Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::GooglePlaces,
            'company_number' => '222',
            'website' => 'https://group.test',
            'dedupe_key' => 'no:222',
        ]);
    });

    dedupe($team, $weaker);

    // Two distinct registered entities on one group site stay separate.
    expect(leadCount($team))->toBe(2);
});

it('does not merge leads that only share a blocked aggregator domain', function (): void {
    $team = Team::factory()->create();

    $b = app(TeamContext::class)->run($team, function () use ($team): Lead {
        Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::WebSearch,
            'company_number' => null,
            'website' => 'https://facebook.com/acme',
            'dedupe_key' => 'name:web:acme:aaa',
        ]);

        return Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::WebSearch,
            'company_number' => null,
            'website' => 'https://facebook.com/beta',
            'dedupe_key' => 'name:web:beta:bbb',
        ]);
    });

    dedupe($team, $b);

    expect(leadCount($team))->toBe(2);
});

it('never merges a same-domain lead belonging to another team', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    app(TeamContext::class)->run($teamA, fn (): Lead => Lead::factory()->create([
        'team_id' => $teamA->getKey(),
        'company_number' => null,
        'website' => 'https://acme.test',
        'dedupe_key' => 'domain:acme.test',
    ]));

    $bLead = app(TeamContext::class)->run($teamB, fn (): Lead => Lead::factory()->create([
        'team_id' => $teamB->getKey(),
        'company_number' => null,
        'website' => 'https://acme.test',
        'dedupe_key' => 'domain:acme.test',
    ]));

    dedupe($teamB, $bLead);

    expect(leadCount($teamA))->toBe(1)
        ->and(leadCount($teamB))->toBe(1);
});

it('promotes enrichment bookkeeping (status, freshness, cost) to the survivor', function (): void {
    $team = Team::factory()->create();

    $survivorLead = app(TeamContext::class)->run($team, function () use ($team): Lead {
        // Survivor is the authoritative (Companies House) record but is still Pending with no cost.
        $a = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::CompaniesHouse,
            'company_number' => null,
            'website' => 'https://acme.test',
            'dedupe_key' => 'domain:acme.test',
            'enrichment_status' => EnrichmentStatus::Pending,
            'enriched_at' => null,
            'enrichment_cost_micros' => 0,
        ]);

        // The lower-ranked duplicate was already enriched and incurred cost.
        Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::WebSearch,
            'company_number' => null,
            'website' => 'https://www.acme.test',
            'dedupe_key' => 'name:web:acme:zzz',
            'enrichment_status' => EnrichmentStatus::Enriched,
            'enriched_at' => now(),
            'enrichment_cost_micros' => 500,
        ]);

        return $a;
    });

    $survivor = dedupe($team, $survivorLead);

    expect(leadCount($team))->toBe(1)
        ->and($survivor->enrichment_status)->toBe(EnrichmentStatus::Enriched)
        ->and($survivor->enriched_at)->not->toBeNull()
        ->and($survivor->enrichment_cost_micros)->toBe(500);
});

it('leaves a lead with no duplicates untouched', function (): void {
    $team = Team::factory()->create();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'company_number' => '111',
        'dedupe_key' => 'no:111',
    ]));

    $survivor = dedupe($team, $lead);

    expect($survivor->getKey())->toBe($lead->getKey())
        ->and(leadCount($team))->toBe(1);
});
