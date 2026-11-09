<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Actions\RequestBulkEnrichmentAction;
use App\Modules\Enrichment\Actions\RequestLeadEnrichmentAction;
use App\Modules\Enrichment\Actions\RevealContactValueAction;
use App\Modules\Enrichment\Contracts\ContactApprovalGate;
use App\Modules\Enrichment\Contracts\CreditReserver;
use App\Modules\Enrichment\Data\CreditReservation;
use App\Modules\Enrichment\Enums\EnrichmentRunState;
use App\Modules\Enrichment\Enums\EnrichmentScope;
use App\Modules\Enrichment\Exceptions\EnrichmentException;
use App\Modules\Enrichment\Jobs\RunEnrichmentWaterfallJob;
use App\Modules\Enrichment\Models\EnrichmentRun;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\Support\LeadsWorkspaceFixtures;

uses(RefreshDatabase::class);

/*
 * Asking for enrichment, and spending to see what it found (PL3).
 *
 * The refusals get the most attention here, because each one is a decision a first draft gets
 * wrong: a capability refusal that leaves a run row puts every plan boundary on the operations
 * screen, a double click that creates a second run buys the same answer twice, and a reveal that
 * is not idempotent charges for it again.
 */

beforeEach(function (): void {
    Queue::fake();

    $this->actor = createUserWithTeam();
    $this->team = $this->actor->currentTeam()->firstOrFail();

    Feature::for($this->team)->activate(PrelaunchFeature::Enrichment->value);
});

function leadWithOneContact(Team $team, array $contact = []): Lead
{
    return app(TeamContext::class)->run($team, function () use ($team, $contact): Lead {
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            ...$contact,
        ]);

        return $lead->fresh(['contacts']);
    });
}

function requestEnrichment(Team $team, Lead $lead, EnrichmentScope $scope, $actor): ?EnrichmentRun
{
    return app(TeamContext::class)->run(
        $team,
        fn (): ?EnrichmentRun => app(RequestLeadEnrichmentAction::class)->handle($team, $lead, $scope, $actor),
    );
}

it('creates a queued run and dispatches the job', function (): void {
    $lead = leadWithOneContact($this->team, ['email' => null, 'email_hash' => null]);

    $run = requestEnrichment($this->team, $lead, EnrichmentScope::Email, $this->actor);

    expect($run)->not->toBeNull()
        ->and($run->state)->toBe(EnrichmentRunState::Queued)
        ->and($run->requested_by)->toBe($this->actor->getKey());

    Queue::assertPushed(RunEnrichmentWaterfallJob::class, 1);
});

it('does nothing at all for a team without the feature', function (): void {
    Feature::for($this->team)->deactivate(PrelaunchFeature::Enrichment->value);

    $lead = leadWithOneContact($this->team);

    expect(requestEnrichment($this->team, $lead, EnrichmentScope::Email, $this->actor))->toBeNull()
        // Not even a refused run: a row saying "this team cannot see this button" is noise.
        ->and(EnrichmentRun::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('leaves no run when the plan does not include enrichment', function (): void {
    $lead = leadWithOneContact($this->team);

    $this->app->instance(CapabilityGate::class, new class implements CapabilityGate
    {
        public function allows(Team $team, Capability $capability): bool
        {
            return $capability !== Capability::Enrichment;
        }
    });

    expect(requestEnrichment($this->team, $lead, EnrichmentScope::Email, $this->actor))->toBeNull()
        // A capability refusal says the feature is not theirs, which is a sales fact rather than a
        // cost event — so it leaves no row, unlike an allowance that was spent.
        ->and(EnrichmentRun::query()->count())->toBe(0);
});

it('hands a second click the run already in flight', function (): void {
    $lead = leadWithOneContact($this->team, ['email' => null, 'email_hash' => null]);

    $first = requestEnrichment($this->team, $lead, EnrichmentScope::Email, $this->actor);
    $second = requestEnrichment($this->team, $lead, EnrichmentScope::Email, $this->actor);

    expect($second->getKey())->toBe($first->getKey())
        ->and(EnrichmentRun::query()->count())->toBe(1);

    // One job, not two: an impatient member must not buy the same answer twice.
    Queue::assertPushed(RunEnrichmentWaterfallJob::class, 1);
});

it('records a refusal when there is nothing to look for', function (): void {
    $lead = app(TeamContext::class)->run(
        $this->team,
        fn (): Lead => Lead::factory()->create(['team_id' => $this->team->getKey()])->fresh(['contacts']),
    );

    // An email scope over a lead with no contacts is a waterfall across an empty list.
    $run = requestEnrichment($this->team, $lead, EnrichmentScope::Email, $this->actor);

    expect($run->state)->toBe(EnrichmentRunState::Refused)
        ->and($run->error_code)->toBe('enrichment.no_contacts')
        // **Refused, not failed** — the distinction the provider panel depends on.
        ->and($run->state->isProviderFailure())->toBeFalse();

    Queue::assertNothingPushed();
});

it('refuses a people-find on a lead enriched inside the window', function (): void {
    config(['enrichment.reenrich_after_days' => 30]);

    $lead = leadWithOneContact($this->team, ['enriched_at' => now()->subDays(2)]);

    $run = requestEnrichment($this->team, $lead, EnrichmentScope::People, $this->actor);

    expect($run->error_code)->toBe('enrichment.recently_enriched');
});

it('refuses a bulk request larger than one click may spend', function (): void {
    config(['enrichment.max_bulk_leads' => 2]);

    expect(fn () => app(RequestBulkEnrichmentAction::class)
        ->handle($this->team, [1, 2, 3], EnrichmentScope::Email, $this->actor))
        // Refused rather than truncated: a member who selected four hundred leads and got eighty
        // enriched would have no way of knowing which eighty.
        ->toThrow(EnrichmentException::class);
});

it('runs a bulk request through the same per-lead decisions', function (): void {
    $withContacts = leadWithOneContact($this->team, ['email' => null, 'email_hash' => null]);

    $without = app(TeamContext::class)->run(
        $this->team,
        fn (): Lead => Lead::factory()->create(['team_id' => $this->team->getKey()]),
    );

    $runs = app(RequestBulkEnrichmentAction::class)->handle(
        $this->team,
        [(int) $withContacts->getKey(), (int) $without->getKey()],
        EnrichmentScope::Email,
        $this->actor,
    );

    // Two runs: one queued, one refused. The bulk path cannot disagree with a single click about
    // what is worth running, because it asks the same Action.
    expect($runs)->toHaveCount(2);

    $states = array_map(static fn (EnrichmentRun $run): string => $run->state->value, $runs);

    expect($states)->toContain('queued')->toContain('refused');
});

it('ignores a lead id that is not the team’s own', function (): void {
    $theirs = Team::factory()->create();
    $theirLead = app(TeamContext::class)->run(
        $theirs,
        fn (): Lead => Lead::factory()->create(['team_id' => $theirs->getKey()]),
    );

    $runs = app(RequestBulkEnrichmentAction::class)->handle(
        $this->team,
        [(int) $theirLead->getKey()],
        EnrichmentScope::Email,
        $this->actor,
    );

    // Read through the team scope, so a foreign id simply is not there — rather than being
    // refused one at a time, which would confirm it exists.
    expect($runs)->toBe([])
        ->and(EnrichmentRun::query()->withoutGlobalScopes()->count())->toBe(0);
});

// -------------------------------------------------------------------------------------------
// Reveals
// -------------------------------------------------------------------------------------------

it('reveals a vendor-found address once, and charges once', function (): void {
    $lead = leadWithOneContact($this->team, [
        'email' => 'jane@acme.test',
        'email_source' => 'zerobounce',
    ]);

    $contact = $lead->contacts()->firstOrFail();

    $reserver = new class implements CreditReserver
    {
        public int $reserves = 0;

        public int $commits = 0;

        public function reserve(Team $team, EnrichmentScope $scope, int $units): CreditReservation
        {
            $this->reserves++;

            return CreditReservation::grant($scope, $units);
        }

        public function commit(CreditReservation $reservation, int $spentMicros, int $unitsUsed): void
        {
            $this->commits++;
        }

        public function release(CreditReservation $reservation): void {}
    };

    $this->app->instance(CreditReserver::class, $reserver);

    $action = app(RevealContactValueAction::class);

    expect($action->email($this->team, $contact, $this->actor))->toBe('jane@acme.test')
        // Second call: already revealed, so it hands the value back and does not reserve again.
        ->and($action->email($this->team, $contact->fresh(), $this->actor))->toBe('jane@acme.test')
        ->and($reserver->reserves)->toBe(1)
        ->and($reserver->commits)->toBe(1);
});

it('does not charge for an address nobody paid for', function (): void {
    $lead = leadWithOneContact($this->team, [
        'email' => 'hello@acme.test',
        // Our own extraction: already visible to the member on the company's own page.
        'email_source' => null,
    ]);

    $contact = $lead->contacts()->firstOrFail();

    $reserver = new class implements CreditReserver
    {
        public int $reserves = 0;

        public function reserve(Team $team, EnrichmentScope $scope, int $units): CreditReservation
        {
            $this->reserves++;

            return CreditReservation::grant($scope, $units);
        }

        public function commit(CreditReservation $reservation, int $spentMicros, int $unitsUsed): void {}

        public function release(CreditReservation $reservation): void {}
    };

    $this->app->instance(CreditReserver::class, $reserver);

    expect(app(RevealContactValueAction::class)->email($this->team, $contact, $this->actor))
        ->toBe('hello@acme.test')
        ->and($reserver->reserves)->toBe(0);
});

it('refuses a reveal the allowance will not cover, without stamping it', function (): void {
    $lead = leadWithOneContact($this->team, [
        'email' => 'jane@acme.test',
        'email_source' => 'zerobounce',
    ]);

    $contact = $lead->contacts()->firstOrFail();

    $this->app->instance(CreditReserver::class, new class implements CreditReserver
    {
        public function reserve(Team $team, EnrichmentScope $scope, int $units): CreditReservation
        {
            return CreditReservation::refuse($scope, 'enrichment.allowance_spent');
        }

        public function commit(CreditReservation $reservation, int $spentMicros, int $unitsUsed): void {}

        public function release(CreditReservation $reservation): void {}
    });

    expect(fn () => app(RevealContactValueAction::class)->email($this->team, $contact, $this->actor))
        ->toThrow(EnrichmentException::class);

    // Nothing stamped, so the value is still masked and a later reveal still has something to buy.
    expect($contact->fresh()->revealed_email_at)->toBeNull()
        ->and($contact->fresh()->requiresEmailReveal())->toBeTrue();
});

it('refuses a reveal the approval gate will not allow', function (): void {
    $lead = leadWithOneContact($this->team, [
        'email' => 'jane@acme.test',
        'email_source' => 'zerobounce',
    ]);

    $contact = $lead->contacts()->firstOrFail();

    $this->app->instance(ContactApprovalGate::class, new class implements ContactApprovalGate
    {
        public function allowsEmail(Team $team, string $email): bool
        {
            return false;
        }

        public function allowsPhone(Team $team, string $phone): bool
        {
            return false;
        }

        public function allowsDomain(Team $team, string $domain): bool
        {
            return false;
        }
    });

    // Asked **before** the charge: a bill for an address that may not be used is the worst of both
    // outcomes.
    expect(fn () => app(RevealContactValueAction::class)->email($this->team, $contact, $this->actor))
        ->toThrow(EnrichmentException::class)
        ->and($contact->fresh()->revealed_email_at)->toBeNull();
});

it('refuses a read-only member a reveal, over HTTP', function (): void {
    $lead = leadWithOneContact($this->team, [
        'email' => 'jane@acme.test',
        'email_source' => 'zerobounce',
    ]);

    $contact = $lead->contacts()->firstOrFail();
    $readOnly = LeadsWorkspaceFixtures::member($this->team, TeamRole::ReadOnly);

    $this->actingAs($readOnly)
        ->post("/leads/{$lead->getKey()}/contacts/{$contact->getKey()}/reveal-email")
        ->assertForbidden();

    expect($contact->fresh()->revealed_email_at)->toBeNull();
});

it('keeps one team’s reveal unreachable from another’s session', function (): void {
    $theirs = Team::factory()->create();
    $theirLead = leadWithOneContact($theirs, ['email' => 'jane@theirs.test', 'email_source' => 'zerobounce']);

    // Read inside **their** context: the global scope is doing its job here, and a test that
    // reached the row without it would be testing a world the application never sees.
    $theirContact = app(TeamContext::class)->run(
        $theirs,
        fn (): LeadContact => $theirLead->contacts()->firstOrFail(),
    );

    $this->actingAs($this->actor)
        ->post("/leads/{$theirLead->getKey()}/contacts/{$theirContact->getKey()}/reveal-email")
        ->assertNotFound();

    expect($theirContact->fresh()->revealed_email_at)->toBeNull();
});

it('refuses a contact id from another lead in the same team', function (): void {
    $lead = leadWithOneContact($this->team, ['email' => 'jane@acme.test', 'email_source' => 'zerobounce']);
    $other = app(TeamContext::class)->run(
        $this->team,
        fn (): Lead => Lead::factory()->create(['team_id' => $this->team->getKey()]),
    );

    $contact = $lead->contacts()->firstOrFail();

    // Resolved **through** the lead, so an id from a sibling lead is a 404 rather than a reveal
    // landing on the wrong company's contact.
    $this->actingAs($this->actor)
        ->post("/leads/{$other->getKey()}/contacts/{$contact->getKey()}/reveal-email")
        ->assertNotFound();
});
