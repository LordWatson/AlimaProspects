<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('scopes lead contacts to the active team and blocks cross-tenant access', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $contactA = app(TeamContext::class)->run($teamA, function () use ($teamA): LeadContact {
        $lead = Lead::factory()->create(['team_id' => $teamA->getKey()]);

        return LeadContact::factory()->create(['team_id' => $teamA->getKey(), 'lead_id' => $lead->getKey()]);
    });

    $contactB = app(TeamContext::class)->run($teamB, function () use ($teamB): LeadContact {
        $lead = Lead::factory()->create(['team_id' => $teamB->getKey()]);

        return LeadContact::factory()->create(['team_id' => $teamB->getKey(), 'lead_id' => $lead->getKey()]);
    });

    // Team A sees only its own contact and cannot resolve team B's by key.
    app(TeamContext::class)->run($teamA, function () use ($contactA, $contactB): void {
        expect(LeadContact::query()->count())->toBe(1)
            ->and(LeadContact::query()->find($contactA->getKey()))->not->toBeNull()
            ->and(LeadContact::query()->find($contactB->getKey()))->toBeNull();
    });

    // And the reverse for team B.
    app(TeamContext::class)->run($teamB, function () use ($contactA): void {
        expect(LeadContact::query()->count())->toBe(1)
            ->and(LeadContact::query()->find($contactA->getKey()))->toBeNull();
    });
});

it('auto-assigns the active team when a contact is created', function (): void {
    $team = Team::factory()->create();

    $contact = app(TeamContext::class)->run($team, function () use ($team): LeadContact {
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        // No team_id passed — the BelongsToTeam trait fills it from the active team.
        return LeadContact::query()->create([
            'lead_id' => $lead->getKey(),
            'email' => 'info@acme.test',
            'source' => 'ai_extract',
        ]);
    });

    expect($contact->team_id)->toBe($team->getKey());
});
