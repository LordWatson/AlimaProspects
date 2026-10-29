<?php

declare(strict_types=1);

use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\FindLeadAction;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

it('redirects a guest away from a lead', function (): void {
    $this->get('/leads/1')->assertRedirect(route('login'));
});

it('renders the company, its contacts, its grade and its timeline', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    $lead = Fixtures::lead($team, [
        'company_name' => 'Bright Bean Coffee',
        'industry' => 'Coffee roasting',
        'website' => 'brightbean.test',
        'employee_range' => '11-50',
        'technologies' => ['Shopify'],
        'enriched_at' => now()->subDay(),
    ], $icp, ScoreGrade::A, 88);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'name' => 'Hello Desk',
            'email' => 'hello@brightbean.test',
        ]);

        LeadActivity::factory()->type(LeadActivityType::Scored, ['grade' => 'A', 'score' => 88])->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
        ]);
    });

    $this->actingAs($user)
        ->get(route('leads.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('leads/Show')
            ->where('lead.id', $lead->getKey())
            ->where('lead.company_name', 'Bright Bean Coffee')
            ->where('lead.industry', 'Coffee roasting')
            ->where('lead.technologies', ['Shopify'])
            ->has('lead.contacts', 1)
            ->where('lead.contacts.0.email', 'hello@brightbean.test')
            ->where('score.grade', 'A')
            ->where('score.score', 88)
            ->has('activities', 1)
            ->where('activities.0.type', 'scored')
            ->where('activities.0.properties.grade', 'A')
            // No member did it, so the timeline attributes it to the platform.
            ->where('activities.0.user', null)
            ->where('activities.0.is_automatic', true));
});

it('renders a bare, ungraded lead without a score or contacts', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team, ['company_name' => 'Bare Ltd']);

    $this->actingAs($user)
        ->get(route('leads.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('score', null)
            ->has('lead.contacts', 0)
            ->has('activities', 0)
            ->where('discoveryRun', null));
});

it('names the run that found the lead', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = app(TeamContext::class)->run($team, function () use ($team): Lead {
        $run = DiscoveryRun::factory()->create(['team_id' => $team->getKey()]);

        return Lead::factory()->create([
            'team_id' => $team->getKey(),
            'discovery_run_id' => $run->getKey(),
        ]);
    });

    $this->actingAs($user)
        ->get(route('leads.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('discoveryRun.id', $lead->discovery_run_id)
            ->where('discoveryRun.source_label', 'Companies House'));
});

it('shows the viewer only their own verdict on the score', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    $lead = Fixtures::lead($team, [], $icp, ScoreGrade::B, 70);

    $this->actingAs($user)
        ->post(route('scoring.feedback', ['lead' => $lead->getKey()]), ['rating' => 'bad'])
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('leads.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('score.my_feedback.rating', 'bad'));
});

it('caps the timeline rather than rendering a lead’s whole history', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);
    $limit = FindLeadAction::TIMELINE_LIMIT;

    app(TeamContext::class)->run($team, function () use ($team, $lead, $limit): void {
        LeadActivity::factory()->count($limit + 5)->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
        ]);
    });

    $this->actingAs($user)
        ->get(route('leads.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('activities', $limit));
});

it('loads the detail screen without a lazy load', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    $lead = Fixtures::lead($team, [], $icp, ScoreGrade::A, 90);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        LeadContact::factory()->count(3)->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
        ]);

        LeadActivity::factory()->count(4)->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
        ]);
    });

    Model::preventLazyLoading();

    $this->actingAs($user)
        ->get(route('leads.show', ['lead' => $lead->getKey()]))
        ->assertOk();
});

it('404s on a lead id that does not exist', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->get(route('leads.show', ['lead' => 999_999]))
        ->assertNotFound();
});
