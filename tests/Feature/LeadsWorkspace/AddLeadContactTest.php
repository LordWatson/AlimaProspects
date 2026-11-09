<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\ContactIdentity;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Adding a contact by hand (the way out of an empty Contacts panel).
 */
function contacts(): Collection
{
    /** @var Collection<int, LeadContact> $all */
    $all = LeadContact::query()->withoutGlobalScopes()->get();

    return $all;
}

it('stores a contact a member types in, unmasked and with its identity hash', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team, ['has_personal_data' => false]);

    $this->actingAs($user)
        ->from(route('leads.show', ['lead' => $lead->getKey()]))
        ->post(route('leads.contacts.store', ['lead' => $lead->getKey()]), [
            'name' => '  Dana Okafor ',
            'title' => 'Operations Director',
            'email' => ' Dana@Example.com ',
            'phone' => '+44 7700 900123',
        ])
        ->assertRedirect(route('leads.show', ['lead' => $lead->getKey()]));

    $contact = contacts()->sole();

    expect($contact->name)->toBe('Dana Okafor')
        ->and($contact->title)->toBe('Operations Director')
        ->and($contact->email)->toBe('dana@example.com')
        ->and($contact->email_hash)->toBe(ContactIdentity::hash('dana@example.com'))
        ->and($contact->phone_hash)->toBe(ContactIdentity::hash('447700900123'))
        ->and($contact->team_id)->toBe($team->getKey())
        // Nobody paid a vendor for it, so there is nothing to charge to reveal (ADR 0029).
        ->and($contact->email_source)->toBeNull()
        ->and($contact->requiresEmailReveal())->toBeFalse()
        ->and($contact->requiresPhoneReveal())->toBeFalse()
        // Typed in, not checked: the status is left for verification to judge.
        ->and($contact->email_verification_status)->toBeNull()
        ->and($contact->is_personal)->toBeTrue()
        ->and($contact->is_role_based)->toBeFalse()
        ->and($contact->source)->toBe('manual');

    // A named person makes the lead hold personal data, which the compliance screens read.
    expect($lead->refresh()->has_personal_data)->toBeTrue();

    $entry = LeadActivity::query()->withoutGlobalScopes()
        ->where('type', LeadActivityType::ContactAdded)
        ->sole();

    expect($entry->lead_id)->toBe($lead->getKey())
        ->and($entry->properties)->toBe(['contact' => 'Dana Okafor']);

    // The audit row says it happened and never what the address is.
    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'leads.contact_added')->sole();

    expect($audit->properties['has_email'] ?? null)->toBeTrue()
        ->and(json_encode($audit->properties))->not->toContain('dana@example.com');
});

it('treats a role mailbox as the company’s front door rather than a person', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team, ['has_personal_data' => false]);

    $this->actingAs($user)
        ->post(route('leads.contacts.store', ['lead' => $lead->getKey()]), ['email' => 'info@example.com'])
        ->assertRedirect();

    $contact = contacts()->sole();

    expect($contact->is_role_based)->toBeTrue()
        ->and($contact->is_personal)->toBeFalse()
        ->and($lead->refresh()->has_personal_data)->toBeFalse();
});

it('needs something to reach the contact by', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->post(route('leads.contacts.store', ['lead' => $lead->getKey()]), ['name' => 'Dana Okafor'])
        ->assertSessionHasErrors(['email', 'phone']);

    expect(contacts())->toHaveCount(0);
});

it('refuses the whole row rather than dropping a number it can’t use', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    // "ask reception" in the phone field is a typo, and storing the row without it would tell the
    // member it worked. A value we can't normalise is also one no suppression entry can match.
    $this->actingAs($user)
        ->post(route('leads.contacts.store', ['lead' => $lead->getKey()]), [
            'email' => 'dana@example.com',
            'phone' => 'ask reception',
        ])
        ->assertRedirect();

    expect(contacts())->toHaveCount(0);
});

it('refuses to store an address on the team’s do-not-contact list', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    app(SuppressionList::class)->suppress(
        type: SuppressionType::Email,
        value: 'dana@example.com',
        scope: SuppressionScope::Team,
        reason: SuppressionReason::OptOut,
        teamId: (int) $team->getKey(),
    );

    // Not stored and then filtered at send time: a person who asked to be left alone should not be
    // on the row at all (ADR 0032).
    $this->actingAs($user)
        ->post(route('leads.contacts.store', ['lead' => $lead->getKey()]), ['email' => 'dana@example.com'])
        ->assertRedirect();

    expect(contacts())->toHaveCount(0);
});

it('does not make a second contact out of a double submit', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    foreach (['dana@example.com', 'DANA@example.com'] as $email) {
        $this->actingAs($user)
            ->post(route('leads.contacts.store', ['lead' => $lead->getKey()]), ['email' => $email])
            ->assertRedirect();
    }

    expect(contacts())->toHaveCount(1);
});

it('refuses a contact from a read-only member', function (): void {
    [, $team] = Fixtures::team();

    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);
    $lead = Fixtures::lead($team);

    $this->actingAs($readOnly)
        ->post(route('leads.contacts.store', ['lead' => $lead->getKey()]), ['email' => 'dana@example.com'])
        ->assertForbidden();

    expect(contacts())->toHaveCount(0);
});

it('cannot add a contact to another team’s lead', function (): void {
    [$user] = Fixtures::team();
    [, $otherTeam] = Fixtures::team();

    $theirLead = Fixtures::lead($otherTeam);

    // 404 and not 403: the global scope hides the row before any policy runs, so the request can't
    // even learn that the lead exists (ADR 0006).
    $this->actingAs($user)
        ->post(route('leads.contacts.store', ['lead' => $theirLead->getKey()]), ['email' => 'dana@example.com'])
        ->assertNotFound();

    expect(contacts())->toHaveCount(0);
});

it('keeps the contact on the lead it was added to', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->post(route('leads.contacts.store', ['lead' => $lead->getKey()]), ['phone' => '07700 900123'])
        ->assertRedirect();

    $contact = app(TeamContext::class)->run($team, fn (): LeadContact => $lead->contacts()->sole());

    expect($contact->phone)->toBe('07700 900123')
        ->and($contact->is_personal)->toBeTrue();
});
