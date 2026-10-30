<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Models\OutreachSuppression;
use Database\Factories\OutreachSuppressionFactory;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\OutreachFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The outreach screen and the do-not-contact screen (Phase 11).
 *
 * The point of these: the **guard decision is recomputed on every render**, so a channel that
 * closed since the member last looked is disabled without anyone having to remember to refresh it.
 */
beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);
    Mail::fake();
    config()->set('outreach.sending_enabled', true);
    config()->set('outreach.from.address', 'outreach@alima.test');
});

it('shows the outreach screen with a decision for every channel', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team);

    app(TeamContext::class)->run($team, fn () => OutreachMessage::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'lead_contact_id' => $contact->getKey(),
    ]));

    $this->actingAs($user)
        ->get(route('outreach.show', $lead->getKey()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('outreach/Show')
            ->where('lead.id', $lead->id)
            ->has('contacts', 1)
            ->has('messages', 1)
            ->has('decisions', count(OutreachChannel::cases()))
            ->where('decisions.email.allowed', true)
            ->where('restriction', null)
            ->where('can.work', true));
});

it('closes the email channel on screen for a restricted lead, and says why', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead] = Fixtures::restrictedLead($team, contactEmail: 'dana@acme.test');

    $this->actingAs($user)
        ->get(route('outreach.show', $lead->getKey()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('decisions.email.allowed', false)
            ->where('decisions.email.reasons.0.value', 'restricted')
            // The permitted channels, offered instead (docs/modules/outreach.md point 3).
            ->where('decisions.linkedin.allowed', true)
            ->where('decisions.call.allowed', true)
            // And a standing note, before the member tries rather than as a refusal afterwards.
            ->where('restriction.value', 'uae_prior_consent')
            ->where('restriction.allows_direct_marketing', false));
});

it('closes the email channel when the contact is already on the list', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead] = Fixtures::lead($team, contactEmail: 'stop@acme.test');

    OutreachSuppressionFactory::new()->forValue(SuppressionType::Email, 'stop@acme.test')->create();

    $this->actingAs($user)
        ->get(route('outreach.show', $lead->getKey()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('decisions.email.allowed', false)
            ->where('decisions.email.reasons.0.value', 'suppressed'));
});

it('says the sender is missing rather than silently offering a send that would fail', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    [$lead] = Fixtures::lead($team, contactEmail: 'dana@acme.test');

    $this->actingAs($user)
        ->get(route('outreach.show', $lead->getKey()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('decisions.email.allowed', false)
            ->where('decisions.email.reasons.0.value', 'no_sender_identity')
            // Fixable, not final — which is a different sentence on screen.
            ->where('decisions.email.reasons.0.is_compliance', false));
});

it('lets a member edit the words of a draft but not its channel', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead] = Fixtures::lead($team);

    $message = app(TeamContext::class)->run($team, fn (): OutreachMessage => OutreachMessage::factory()
        ->channel(OutreachChannel::Call)
        ->create(['team_id' => $team->getKey(), 'lead_id' => $lead->getKey()]));

    $this->actingAs($user)
        ->patch(route('outreach.messages.update', $message->getKey()), [
            'subject' => 'Ignored on a call script',
            'body' => 'My own words.',
            // Not in the request's rules, so it cannot take effect.
            'channel' => 'email',
        ])
        ->assertRedirect();

    $updated = $message->fresh();

    expect($updated->body)->toBe('My own words.')
        ->and($updated->channel)->toBe(OutreachChannel::Call)
        // A call script has no subject line, so one offered is dropped rather than stored.
        ->and($updated->subject)->toBeNull();
});

it('lets the author delete a draft and refuses a stranger', function (): void {
    [$author, $team] = Fixtures::team();
    [$lead] = Fixtures::lead($team);

    $colleague = Fixtures::member($team, TeamRole::Member);

    $message = app(TeamContext::class)->run($team, fn (): OutreachMessage => OutreachMessage::factory()
        ->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'created_by' => $author->getKey(),
        ]));

    $this->actingAs($colleague)
        ->delete(route('outreach.messages.destroy', $message->getKey()))
        ->assertForbidden();

    $this->actingAs($author)
        ->delete(route('outreach.messages.destroy', $message->getKey()))
        ->assertRedirect();

    expect(OutreachMessage::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a read-only member the draft button but still shows them the screen', function (): void {
    [, $team] = Fixtures::team();
    [$lead] = Fixtures::lead($team);

    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);

    // Read-only can *see* how the team approaches a company — that is part of understanding the
    // pipeline — and the screen says so by sending `can.work: false`.
    $this->actingAs($readOnly)
        ->get(route('outreach.show', $lead->getKey()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.work', false));

    $this->actingAs($readOnly)
        ->post(route('outreach.draft', $lead->getKey()), ['channel' => 'email', 'tone' => 'direct'])
        ->assertForbidden();
});

it('cannot open another team\'s outreach screen', function (): void {
    [$user] = Fixtures::team();
    [, $otherTeam] = Fixtures::team('Other Co');
    [$otherLead] = Fixtures::lead($otherTeam);

    $this->actingAs($user)
        ->get(route('outreach.show', $otherLead->getKey()))
        ->assertNotFound();
});

// --- The do-not-contact screen -------------------------------------------------------------------

it('shows the team\'s own entries and every global one, masked', function (): void {
    [$user, $team] = Fixtures::team();

    OutreachSuppressionFactory::new()->forValue(SuppressionType::Email, 'stop@acme.test')->create();
    OutreachSuppressionFactory::new()
        ->forValue(SuppressionType::Domain, 'rival.test')
        ->forTeam((int) $team->getKey())
        ->create();

    // Another team's housekeeping, which this team must not see.
    [, $otherTeam] = Fixtures::team('Other Co');
    OutreachSuppressionFactory::new()
        ->forValue(SuppressionType::Email, 'theirs@elsewhere.test')
        ->forTeam((int) $otherTeam->getKey())
        ->create();

    $this->actingAs($user)
        ->get(route('outreach.suppressions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('outreach/Suppressions')
            ->has('entries', 2)
            ->where('can.manage', true));
});

it('refuses a plain member the do-not-contact list', function (): void {
    [, $team] = Fixtures::team();

    $member = Fixtures::member($team, TeamRole::Member);

    $this->actingAs($member)
        ->post(route('outreach.suppressions.store'), ['type' => 'domain', 'value' => 'rival.test'])
        ->assertForbidden();

    expect(OutreachSuppression::query()->count())->toBe(0);
});

it('cannot lift another team\'s entry', function (): void {
    [$user] = Fixtures::team();
    [, $otherTeam] = Fixtures::team('Other Co');

    $theirs = OutreachSuppressionFactory::new()
        ->forValue(SuppressionType::Email, 'theirs@elsewhere.test')
        ->forTeam((int) $otherTeam->getKey())
        ->create();

    // Scoped by `scope_key` rather than by a global scope this model deliberately lacks.
    $this->actingAs($user)
        ->delete(route('outreach.suppressions.destroy', $theirs->getKey()))
        ->assertNotFound();

    expect(OutreachSuppression::query()->whereKey($theirs->getKey())->exists())->toBeTrue();
});
