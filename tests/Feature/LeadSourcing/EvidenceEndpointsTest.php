<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Actions\RecordLeadEvidenceAction;
use App\Modules\LeadSourcing\Data\EvidenceItem;
use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Enums\EvidenceVerdict;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Tests\Support\LeadsWorkspaceFixtures;

uses(RefreshDatabase::class);

/*
 * The two ways a member reaches a lead's reasons (PL1): the web detail screen and `/api/v1`, which
 * the mobile app reads.
 *
 * Tenant isolation is asserted through **HTTP**, not only through the Action, because that is the
 * boundary an attacker reaches: the global scope hides the row and the policy refuses it, and the
 * test exists to prove the pair of them are actually wired to the route.
 */

beforeEach(function (): void {
    Queue::fake();
});

/**
 * A lead with one filing and one news story, plus the member who can see it.
 *
 * @return array{User, Team, Lead, LeadEvidence}
 */
function leadWithReasons(): array
{
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    [$lead, $evidence] = app(TeamContext::class)->run($team, function () use ($team): array {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::CompaniesHouse,
            'incorporated_on' => null,
            'collected_at' => now(),
        ]);

        // Recorded **through the Action**, not straight into the table with a factory: the Action
        // is what recalculates the lead's confidence, and a test whose `leads.confidence` was
        // computed before half its evidence existed would prove nothing about what flagging does
        // to it.
        $news = app(RecordLeadEvidenceAction::class)->handle($lead, new EvidenceItem(
            kind: EvidenceKind::Signal,
            source: 'news',
            title: 'Raises Series A funding',
            summary: 'Closed a round led by an institutional investor.',
            sourceLabel: 'Example Wire',
            sourceUrl: 'https://example.test/news/round',
            observedAt: now()->subDays(2),
        ), $team);

        return [$lead->fresh(), $news];
    });

    Feature::for($team)->activate(PrelaunchFeature::WhyNow->value);

    return [$actor, $team, $lead, $evidence];
}

it('shows the why-now panel with its evidence on the detail screen', function (): void {
    [$actor, , $lead] = leadWithReasons();

    $this->actingAs($actor)
        ->get("/leads/{$lead->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('whyNow', true)
            ->where('can.flagEvidence', true)
            ->has('lead.evidence', 2)
            // The provider's raw payload never leaves the database: it is kept for audit and on a
            // signal-derived row it can name a person (docs/compliance.md §3.9).
            ->missing('lead.evidence.0.payload')
            ->has('lead.confidence')
            ->has('lead.confidence_band'));
});

it('does not render the panel for a team without the feature', function (): void {
    [$actor, $team, $lead] = leadWithReasons();

    Feature::for($team)->deactivate(PrelaunchFeature::WhyNow->value);

    $this->actingAs($actor)
        ->get("/leads/{$lead->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('whyNow', false));
});

it('counts the hidden leads on the list, and reveals them on request', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $team->forceFill(['min_lead_confidence' => 90])->save();

    app(TeamContext::class)->run($team, fn () => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::WebSearch,
        'incorporated_on' => null,
        'collected_at' => now(),
    ]));

    Feature::for($team)->activate(PrelaunchFeature::WhyNow->value);

    $this->actingAs($actor)
        ->get('/leads')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('hiddenCount', 1)
            ->where('minConfidence', 90)
            ->has('leads.data', 0));

    $this->actingAs($actor)
        ->get('/leads?include_low_confidence=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Nothing is hidden any more, so there is nothing to count.
            ->where('hiddenCount', 0)
            ->has('leads.data', 1));
});

it('lets a member flag a reason from the detail screen and lowers the confidence', function (): void {
    [$actor, , $lead, $evidence] = leadWithReasons();

    $before = (int) $lead->fresh()->confidence;

    $this->actingAs($actor)
        ->from("/leads/{$lead->getKey()}")
        ->post("/leads/{$lead->getKey()}/evidence/{$evidence->getKey()}/feedback", [
            'verdict' => EvidenceVerdict::Wrong->value,
            'note' => 'Different company of the same name.',
        ])
        ->assertRedirect("/leads/{$lead->getKey()}");

    expect($evidence->fresh()->is_disputed)->toBeTrue()
        ->and((int) $lead->fresh()->confidence)->toBeLessThan($before);
});

it('refuses a verdict it does not recognise', function (): void {
    [$actor, , $lead, $evidence] = leadWithReasons();

    $this->actingAs($actor)
        ->from("/leads/{$lead->getKey()}")
        ->post("/leads/{$lead->getKey()}/evidence/{$evidence->getKey()}/feedback", ['verdict' => 'nonsense'])
        ->assertSessionHasErrors('verdict');
});

it('refuses a read-only member', function (): void {
    [, $team, $lead, $evidence] = leadWithReasons();

    $readOnly = LeadsWorkspaceFixtures::member($team, TeamRole::ReadOnly);

    // Reading why a lead is on the list is fine; disputing a fact changes the figure for the whole
    // team, which is work.
    $this->actingAs($readOnly)->get("/leads/{$lead->getKey()}")->assertOk();

    $this->actingAs($readOnly)
        ->post("/leads/{$lead->getKey()}/evidence/{$evidence->getKey()}/feedback", [
            'verdict' => EvidenceVerdict::Wrong->value,
        ])
        ->assertForbidden();

    expect($evidence->fresh()->is_disputed)->toBeFalse();
});

it('serves a lead’s evidence over the API with the caller’s own verdict', function (): void {
    [$actor, , $lead, $evidence] = leadWithReasons();

    Sanctum::actingAs($actor, [TokenAbility::LeadsWrite->value, TokenAbility::LeadsRead->value]);

    $this->postJson("/api/v1/leads/{$lead->getKey()}/evidence/{$evidence->getKey()}/feedback", [
        'verdict' => EvidenceVerdict::Useful->value,
    ])->assertOk()->assertJsonPath('data.my_verdict', 'useful');

    $this->getJson("/api/v1/leads/{$lead->getKey()}/evidence")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissingPath('data.0.payload');
});

it('refuses an API token without the leads scope', function (): void {
    [$actor, , $lead] = leadWithReasons();

    Sanctum::actingAs($actor, [TokenAbility::SignalsRead->value]);

    $this->getJson("/api/v1/leads/{$lead->getKey()}/evidence")->assertForbidden();
});

it('keeps one team’s evidence unreachable from another’s session', function (): void {
    [, , , $evidence] = leadWithReasons();
    $theirLeadId = $evidence->lead_id;

    $outsider = createUserWithTeam();

    // A 404 rather than a 403: the lead is resolved through a team-scoped query, so to the other
    // team it does not exist — which is the answer that leaks nothing about whether it does.
    $this->actingAs($outsider)->get("/leads/{$theirLeadId}")->assertNotFound();

    $this->actingAs($outsider)
        ->post("/leads/{$theirLeadId}/evidence/{$evidence->getKey()}/feedback", [
            'verdict' => EvidenceVerdict::Wrong->value,
        ])
        ->assertNotFound();

    Sanctum::actingAs($outsider, [TokenAbility::LeadsRead->value]);

    $this->getJson("/api/v1/leads/{$theirLeadId}/evidence")->assertNotFound();

    expect($evidence->fresh()->is_disputed)->toBeFalse();
});

it('refuses a reason that belongs to another lead in the same team', function (): void {
    [$actor, $team, , $evidence] = leadWithReasons();

    $other = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::CompaniesHouse,
    ]));

    // The evidence is resolved **through the lead**, so an id from a sibling lead is a 404 rather
    // than a flag landing on the wrong company's reason.
    $this->actingAs($actor)
        ->post("/leads/{$other->getKey()}/evidence/{$evidence->getKey()}/feedback", [
            'verdict' => EvidenceVerdict::Wrong->value,
        ])
        ->assertNotFound();
});
