<?php

declare(strict_types=1);

use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

it('refuses an unauthenticated caller', function (): void {
    $this->getJson(route('api.v1.leads.index'))->assertUnauthorized();
    $this->getJson(route('api.v1.leads.show', ['lead' => 1]))->assertUnauthorized();
});

it('returns a paginated page of leads as resources', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee'], $icp, ScoreGrade::A, 88);
    Fixtures::lead($team, ['company_name' => 'Second Ltd', 'dedupe_key' => 'no:second']);

    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.leads.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'company_name', 'source', 'enrichment_status', 'contacts_count']],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ])
        // Never the model: no `raw` provider payload, no `field_sources`, no `team_id`.
        ->assertJsonMissingPath('data.0.team_id')
        ->assertJsonMissingPath('data.0.raw');
});

it('applies the same filters as the web list', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Strong fit'], $icp, ScoreGrade::A, 90);
    Fixtures::lead($team, [
        'company_name' => 'Weak fit',
        'dedupe_key' => 'no:weak',
        'source' => LeadSource::WebSearch,
    ], $icp, ScoreGrade::D, 10);

    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.leads.index', ['grades' => ['A']]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.company_name', 'Strong fit');

    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.leads.index', ['sources' => ['web_search']]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.company_name', 'Weak fit');
});

it('honours per_page and caps it', function (): void {
    [$user, $team] = Fixtures::team();

    foreach (range(1, 3) as $i) {
        Fixtures::lead($team, ['company_name' => 'Acme '.$i, 'dedupe_key' => 'no:acme-'.$i]);
    }

    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.leads.index', ['per_page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3);

    // Asking for the whole table is a validation error, not a slow response.
    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.leads.index', ['per_page' => 5_000]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

it('returns the lead, its score and its timeline in one response', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee'], $icp, ScoreGrade::B, 72);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'email' => 'hello@brightbean.test',
        ]);

        LeadActivity::factory()->type(LeadActivityType::Enriched, ['contacts' => 1])->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
        ]);
    });

    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.leads.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertJsonPath('data.lead.company_name', 'Bright Bean Coffee')
        ->assertJsonPath('data.lead.contacts.0.email', 'hello@brightbean.test')
        ->assertJsonPath('data.score.grade', 'B')
        ->assertJsonPath('data.score.score', 72)
        ->assertJsonPath('data.activities.0.type', 'enriched')
        ->assertJsonPath('data.activities.0.properties.contacts', 1);
});

it('returns an ungraded lead with a null score rather than erroring', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.leads.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertJsonPath('data.score', null)
        ->assertJsonCount(0, 'data.activities');
});

it('404s on a lead that does not exist', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.leads.show', ['lead' => 999_999]))
        ->assertNotFound();
});
