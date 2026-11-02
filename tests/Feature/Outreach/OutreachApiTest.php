<?php

declare(strict_types=1);

use App\Modules\Core\Support\TeamContext;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachMessageStatus;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Models\OutreachTemplate;
use Database\Factories\OutreachSuppressionFactory;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OutreachFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Outreach over `/api/v1` (Phase 11) — the surface the mobile app works from.
 *
 * The case that matters most here is `decisions`: a phone has to know which channels are open
 * **before** it offers a compose screen, because discovering at send time that a lead may not be
 * cold-emailed is a wasted draft and a baffling error.
 */
beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);
    Mail::fake();
    config()->set('outreach.sending_enabled', true);
    config()->set('outreach.from.address', 'outreach@alima.test');
});

it('reports which channels a lead may be approached on, and why not', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead] = Fixtures::restrictedLead($team, contactEmail: 'dana@acme.test');

    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson(route('api.v1.outreach.decisions', $lead->getKey()))->assertOk();

    $decisions = collect($response->json('data'))->keyBy('channel');

    expect($decisions['email']['allowed'])->toBeFalse()
        ->and($decisions['email']['reasons'][0]['value'])->toBe('restricted')
        // Final, not fixable — which is what the client needs to know to word its own message.
        ->and($decisions['email']['reasons'][0]['is_compliance'])->toBeTrue()
        // The permitted channels, offered instead.
        ->and($decisions['linkedin']['allowed'])->toBeTrue()
        ->and($decisions['call']['allowed'])->toBeTrue();
});

it('drafts over the API and returns 201', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team);

    Fixtures::fakeAi()->queueStructured(Fixtures::aiDraft());

    Sanctum::actingAs($user, ['*']);

    $this->postJson(route('api.v1.outreach.draft', $lead->getKey()), [
        'channel' => 'email',
        'tone' => 'warm',
        'locale' => 'en',
        'lead_contact_id' => $contact->getKey(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.channel', 'email')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.can_send', true)
        // The address is deliberately not in the payload: the client already knows the contact, and
        // a prospect's address has no business in a list view (docs/compliance.md §3.9).
        ->assertJsonMissingPath('data.sent_to');
});

it('returns a compliance error envelope when the guard refuses a draft', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::restrictedLead($team);

    Sanctum::actingAs($user, ['*']);

    $this->postJson(route('api.v1.outreach.draft', $lead->getKey()), [
        'channel' => 'email',
        'tone' => 'direct',
        'lead_contact_id' => $contact->getKey(),
    ])
        ->assertStatus(422)
        // A machine-readable code, so a client tells "never" from "not yet" without parsing prose.
        ->assertJsonPath('error.code', 'outreach_blocked_compliance');
});

it('lists a lead\'s messages and attempts', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team);

    app(TeamContext::class)->run($team, function () use ($team, $lead, $contact): void {
        OutreachMessage::factory()->count(3)->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
        ]);
        ContactAttempt::factory()->count(2)->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'lead_contact_id' => $contact->getKey(),
        ]);
    });

    Sanctum::actingAs($user, ['*']);

    $this->getJson(route('api.v1.outreach.messages.index', $lead->getKey()))
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $this->getJson(route('api.v1.outreach.attempts.index', $lead->getKey()))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('logs an attempt and records its outcome', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team);

    Sanctum::actingAs($user, ['*']);

    $created = $this->postJson(route('api.v1.outreach.attempts.store', $lead->getKey()), [
        'channel' => 'call',
        'outcome' => 'no_answer',
        'lead_contact_id' => $contact->getKey(),
        'notes' => 'Voicemail.',
    ])->assertCreated();

    $id = $created->json('data.id');

    $this->patchJson(route('api.v1.outreach.attempts.update', $id), ['outcome' => 'meeting_booked'])
        ->assertOk()
        ->assertJsonPath('data.outcome', 'meeting_booked')
        ->assertJsonPath('data.is_positive', true);
});

it('sends over the API', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team, contactEmail: 'dana@acme.test');

    $message = app(TeamContext::class)->run($team, fn (): OutreachMessage => OutreachMessage::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'lead_contact_id' => $contact->getKey(),
    ]));

    Sanctum::actingAs($user, ['*']);

    $this->postJson(route('api.v1.outreach.messages.send', $message->getKey()))
        ->assertOk()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.is_spent', true)
        ->assertJsonPath('data.can_send', false);
});

it('manages briefs over the API', function (): void {
    [$user] = Fixtures::team();

    Sanctum::actingAs($user, ['*']);

    $created = $this->postJson(route('api.v1.outreach.templates.store'), [
        'name' => 'First touch',
        'channel' => 'linkedin',
        'tone' => 'warm',
        'body' => 'Lead with the referral.',
        'is_default' => true,
    ])->assertCreated();

    $id = $created->json('data.id');

    $this->getJson(route('api.v1.outreach.templates.index'))->assertOk()->assertJsonCount(1, 'data');

    $this->patchJson(route('api.v1.outreach.templates.update', $id), [
        'name' => 'First touch',
        'channel' => 'linkedin',
        'tone' => 'formal',
        'body' => 'Changed.',
    ])->assertOk()->assertJsonPath('data.tone', 'formal');

    $this->deleteJson(route('api.v1.outreach.templates.destroy', $id))->assertNoContent();

    expect(OutreachTemplate::withoutGlobalScopes()->count())->toBe(0);
});

it('lists the do-not-contact entries with a masked preview and no hash', function (): void {
    [$user, $team] = Fixtures::team();

    OutreachSuppressionFactory::new()->forValue(SuppressionType::Email, 'stop@acme.test')->create();
    OutreachSuppressionFactory::new()
        ->forValue(SuppressionType::Domain, 'rival.test')
        ->forTeam((int) $team->getKey())
        ->create();

    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson(route('api.v1.outreach.suppressions.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $entries = collect($response->json('data'))->keyBy('type');

    expect($entries['email']['value_preview'])->toBe('s***@acme.test')
        // Exposing the hash would hand a client an oracle it could test any address against.
        ->and($entries['email'])->not->toHaveKey('value_hash')
        // A global opt-out is visible — it is why a send was refused — but not removable.
        ->and($entries['email']['can_delete'])->toBeFalse()
        ->and($entries['domain']['can_delete'])->toBeTrue();
});

it('adds and lifts a team entry, and refuses to lift a global opt-out', function (): void {
    [$user, $team] = Fixtures::team();

    Sanctum::actingAs($user, ['*']);

    $created = $this->postJson(route('api.v1.outreach.suppressions.store'), [
        'type' => 'domain',
        'value' => 'https://www.rival.test/about',
        'note' => 'Existing client',
    ])->assertCreated();

    // A pasted URL where a domain was meant is normalised rather than refused.
    expect($created->json('data.value_preview'))->toBe('rival.test');

    $this->deleteJson(route('api.v1.outreach.suppressions.destroy', $created->json('data.id')))
        ->assertNoContent();

    $global = OutreachSuppressionFactory::new()
        ->forValue(SuppressionType::Email, 'stop@acme.test')
        ->create();

    $this->deleteJson(route('api.v1.outreach.suppressions.destroy', $global->getKey()))
        ->assertForbidden();

    expect(OutreachSuppression::query()->whereKey($global->getKey())->exists())->toBeTrue()
        ->and($global->reason)->toBe(SuppressionReason::OptOut);
});

it('refuses an unusable suppression value with a readable reason', function (): void {
    [$user] = Fixtures::team();

    Sanctum::actingAs($user, ['*']);

    $this->postJson(route('api.v1.outreach.suppressions.store'), [
        'type' => 'email',
        'value' => 'not an address',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'outreach_unusable_suppression_value');
});

// --- Tenancy -------------------------------------------------------------------------------------

it('cannot reach another team\'s lead, message or attempt', function (): void {
    [$user] = Fixtures::team();
    [, $otherTeam] = Fixtures::team('Other Co');
    [$otherLead, $otherContact] = Fixtures::lead($otherTeam);

    $otherMessage = app(TeamContext::class)->run($otherTeam, fn (): OutreachMessage => OutreachMessage::factory()
        ->create(['team_id' => $otherTeam->getKey(), 'lead_id' => $otherLead->getKey()]));

    $otherAttempt = app(TeamContext::class)->run($otherTeam, fn (): ContactAttempt => ContactAttempt::factory()
        ->create([
            'team_id' => $otherTeam->getKey(),
            'lead_id' => $otherLead->getKey(),
            'lead_contact_id' => $otherContact->getKey(),
        ]));

    Sanctum::actingAs($user, ['*']);

    // A 404 before any policy runs: the global scope hides the row, so the request can't even
    // learn that it exists.
    $this->getJson(route('api.v1.outreach.messages.index', $otherLead->getKey()))->assertNotFound();
    $this->getJson(route('api.v1.outreach.decisions', $otherLead->getKey()))->assertNotFound();
    $this->getJson(route('api.v1.outreach.messages.show', $otherMessage->getKey()))->assertNotFound();
    $this->postJson(route('api.v1.outreach.messages.send', $otherMessage->getKey()))->assertNotFound();
    $this->patchJson(route('api.v1.outreach.attempts.update', $otherAttempt->getKey()), [
        'outcome' => 'replied',
    ])->assertNotFound();

    expect($otherMessage->fresh()->status)->toBe(OutreachMessageStatus::Draft);
});

it('requires a token', function (): void {
    [, $team] = Fixtures::team();
    [$lead] = Fixtures::lead($team);

    $this->getJson(route('api.v1.outreach.messages.index', $lead->getKey()))->assertUnauthorized();
    $this->getJson(route('api.v1.outreach.templates.index'))->assertUnauthorized();
    $this->getJson(route('api.v1.outreach.suppressions.index'))->assertUnauthorized();

    expect(OutreachChannel::values())->toContain('email');
});
