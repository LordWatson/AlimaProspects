<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\ContactIdentity;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Enums\ContactEnrichmentState;
use App\Modules\Enrichment\Enums\PhoneType;
use App\Modules\Enrichment\Http\Resources\LeadContactResource;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * What a screen is allowed to see of a contact (PL2), and the identity underneath it.
 *
 * The reveal rule is the decision worth testing hardest, because the obvious implementation is
 * wrong in a way nobody would notice until a customer complained: masking **everything** would
 * have hidden the addresses the free-tier extraction has been finding since Phase 8 and that
 * Outreach has been sending to since Phase 11 — a regression shipped inside a feature, and a
 * charge for showing a member something printed on the prospect's own contact page.
 *
 * So masking follows **who was paid**, not whether a value exists.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->team = Team::factory()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function contactFor(Team $team, array $attributes = []): LeadContact
{
    return app(TeamContext::class)->run($team, function () use ($team, $attributes): LeadContact {
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        return LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            ...$attributes,
        ]);
    });
}

/**
 * @return array<string, mixed>
 */
function serialise(LeadContact $contact): array
{
    return LeadContactResource::make($contact)->resolve(Request::create('/'));
}

it('shows an address we read off a public page, whole', function (): void {
    $contact = contactFor($this->team, [
        'email' => 'hello@acme.test',
        // Null source = our own extraction. Nobody paid for this.
        'email_source' => null,
    ]);

    expect($contact->requiresEmailReveal())->toBeFalse()
        ->and($contact->displayEmail())->toBe('hello@acme.test')
        ->and(serialise($contact)['email'])->toBe('hello@acme.test')
        ->and(serialise($contact)['requires_email_reveal'])->toBeFalse();
});

it('masks an address a vendor was paid for, until a member spends on it', function (): void {
    $contact = contactFor($this->team, [
        'email' => 'jane.doe@acme.test',
        'email_source' => 'zerobounce',
    ]);

    expect($contact->requiresEmailReveal())->toBeTrue()
        ->and($contact->displayEmail())->toBe('j***@acme.test');

    $payload = serialise($contact);

    // One field, so there is nothing to walk around: a `masked_email` beside a plain `email` is a
    // mask any client can step over, and the first one that does gives away what PL4 charges for.
    expect($payload['email'])->toBe('j***@acme.test')
        ->and($payload)->not->toHaveKey('email_raw')
        ->and(json_encode($payload))->not->toContain('jane.doe@acme.test');

    $contact->forceFill(['revealed_email_at' => Carbon::now()])->save();

    expect($contact->fresh()->requiresEmailReveal())->toBeFalse()
        ->and(serialise($contact->fresh())['email'])->toBe('jane.doe@acme.test');
});

it('never masks a switchboard number, even one a vendor supplied', function (): void {
    $contact = contactFor($this->team, [
        'phone' => '+44 20 7946 0000',
        'phone_type' => PhoneType::Switchboard,
        'phone_source' => 'somevendor',
    ]);

    // The company's own published number is a business fact, not a person's contact detail.
    // Charging to reveal something a prospect prints on its homepage is not a product, it is a toll.
    expect($contact->requiresPhoneReveal())->toBeFalse()
        ->and($contact->displayPhone())->toBe('+44 20 7946 0000');
});

it('masks a mobile a vendor found, and says it can take a message', function (): void {
    $contact = contactFor($this->team, [
        'phone' => '+447700904821',
        'phone_type' => PhoneType::Mobile,
        'phone_source' => 'somevendor',
    ]);

    $payload = serialise($contact);

    expect($contact->requiresPhoneReveal())->toBeTrue()
        ->and($payload['phone'])->toBe('***4821')
        // A client has to be able to choose between "Reveal" and "Find a number" without being
        // told the number.
        ->and($payload['has_phone'])->toBeTrue()
        ->and($payload['phone_accepts_messaging'])->toBeTrue();
});

it('treats a number of unknown kind as a person’s', function (): void {
    $contact = contactFor($this->team, [
        'phone' => '+447700904821',
        'phone_type' => PhoneType::Unknown,
        'phone_source' => 'somevendor',
    ]);

    // The cautious direction: a vendor that did not say what kind of number it found has not told
    // us it is a company's, and the costly mistake is the other one.
    expect($contact->requiresPhoneReveal())->toBeTrue();
});

it('writes an address and its identity together, or neither', function (): void {
    $contact = contactFor($this->team, ['email' => null, 'email_hash' => null]);

    expect($contact->setEmail('  Jane@Acme.test ', 'zerobounce'))->toBeTrue();

    // The hash is what matches a suppression entry. An `email` written without it is an address
    // that matches nothing on the do-not-contact list, and the failure mode of that is a message
    // sent to somebody who asked us not to.
    expect($contact->email)->toBe('jane@acme.test')
        ->and($contact->email_hash)->toBe(ContactIdentity::hash('jane@acme.test'))
        ->and($contact->email_source)->toBe('zerobounce');

    // And a value that is not an address is refused rather than stored half-written.
    expect($contact->setEmail('not-an-address'))->toBeFalse()
        ->and($contact->email)->toBe('jane@acme.test');
});

it('hashes a number by its digits, not by its dialable form', function (): void {
    $contact = contactFor($this->team, ['phone' => null]);

    $contact->setPhone('+44 7700 904821', PhoneType::Mobile, 'somevendor', e164: '+447700904821');

    // Two different jobs: `phone_e164` is what a member dials, `phone_hash` is what matches a
    // suppression entry — and the second must survive the number being written four ways, which
    // is why it is bare digits and not the `+` form.
    expect($contact->phone_e164)->toBe('+447700904821')
        ->and($contact->phone_hash)->toBe(ContactIdentity::hash('447700904821'))
        ->and($contact->phoneValue())->toBe('+447700904821');
});

it('refuses two contacts with the same address on one lead', function (): void {
    $contact = contactFor($this->team, ['email' => 'jane@acme.test']);
    $contact->setEmail('jane@acme.test');
    $contact->save();

    app(TeamContext::class)->run($this->team, function () use ($contact): void {
        $second = new LeadContact;
        $second->forceFill([
            'team_id' => $this->team->getKey(),
            'lead_id' => $contact->lead_id,
            'source' => 'ai_extract',
        ]);
        // The same address in different clothes. The unique is on the **identity**, which is what
        // catches this — the shipped unique on the plain value would not have.
        $second->setEmail('JANE@acme.test');

        expect(fn () => $second->save())->toThrow(QueryException::class);
    });
});

it('lets two phone-only contacts coexist', function (): void {
    $lead = app(TeamContext::class)->run(
        $this->team,
        fn (): Lead => Lead::factory()->create(['team_id' => $this->team->getKey()]),
    );

    app(TeamContext::class)->run($this->team, function () use ($lead): void {
        foreach (['+447700904821', '+447700904822'] as $number) {
            $contact = new LeadContact;
            $contact->forceFill([
                'team_id' => $this->team->getKey(),
                'lead_id' => $lead->getKey(),
                'source' => 'ai_extract',
            ]);
            $contact->setPhone($number, PhoneType::Mobile);
            $contact->save();
        }
    });

    // Nulls are distinct in a unique index in both MySQL and SQLite, which is what makes a partial
    // index ("where email_hash is not null") unnecessary — and portable.
    expect($lead->contacts()->count())->toBe(2);
});

it('starts a contact idle and not in flight', function (): void {
    $contact = contactFor($this->team);

    expect($contact->enrichment_state)->toBe(ContactEnrichmentState::Idle)
        ->and($contact->enrichment_state->isInFlight())->toBeFalse()
        // A state rather than a null, so the UI can render "not looked for" instead of
        // interpreting an absence.
        ->and(serialise($contact)['enrichment_state'])->toBe('idle');
});

it('will not let a reveal be mass-assigned', function (): void {
    $contact = contactFor($this->team, [
        'email' => 'jane@acme.test',
        'email_source' => 'zerobounce',
    ]);

    // A reveal is a **metered act** (PL4), and the only thing allowed to claim one happened is the
    // Action that charged for it. A form that could set this would be a free reveal.
    //
    // It **throws** rather than being silently dropped, because `Model::shouldBeStrict()` is on
    // outside production — so a code path that tried this would fail in development and in CI
    // rather than appearing to work and quietly giving away a paid field.
    expect(fn () => $contact->fill(['revealed_email_at' => Carbon::now()]))
        ->toThrow(MassAssignmentException::class);

    expect($contact->revealed_email_at)->toBeNull()
        ->and($contact->requiresEmailReveal())->toBeTrue();
});

it('keeps one team’s contacts out of another’s reach', function (): void {
    $theirs = Team::factory()->create();
    contactFor($theirs, ['email' => 'jane@theirs.test']);

    $visible = app(TeamContext::class)->run(
        $this->team,
        fn (): int => LeadContact::query()->count(),
    );

    expect($visible)->toBe(0);
});
