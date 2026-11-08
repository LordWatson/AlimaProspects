<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Data\CostProfile;
use App\Modules\Enrichment\Data\FoundPerson;
use App\Modules\Enrichment\Enums\AttemptOutcome;
use App\Modules\Enrichment\Enums\ContactEnrichmentState;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Enums\EnrichmentRunState;
use App\Modules\Enrichment\Enums\EnrichmentScope;
use App\Modules\Enrichment\Enums\PhoneType;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\Enrichment\Models\EnrichmentAttempt;
use App\Modules\Enrichment\Models\EnrichmentRun;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Enrichment\Services\EnrichmentProviderRegistry;
use App\Modules\Enrichment\Services\EnrichmentWaterfall;
use App\Modules\Enrichment\Testing\FakeEmailFinder;
use App\Modules\Enrichment\Testing\FakeEmailVerificationProvider;
use App\Modules\Enrichment\Testing\FakePersonFinder;
use App\Modules\Enrichment\Testing\FakePhoneFinder;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * When to stop paying (PL3).
 *
 * The one piece of judgement the phase is about, so this is where it is tested hardest: that the
 * waterfall stops at the first *good-enough* answer and not the first answer; that a miss, an error
 * and a skip each fall through and each record themselves differently; and that nothing is asked
 * that could not be answered.
 *
 * All of it against fakes, which is deliberate rather than a limitation — with no vendor signed
 * (ADR 0027 §2) the fakes are the only providers there are, and they are what PL4 will meter.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->team = Team::factory()->create();
});

/**
 * A lead with `$contacts` contacts, in the team's context.
 */
function leadWithEmptyContacts(Team $team, int $contacts = 1): Lead
{
    return app(TeamContext::class)->run($team, function () use ($team, $contacts): Lead {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'company_name' => 'Zephyr Logistics Ltd',
            'website' => 'https://zephyr.test',
            'country' => 'GB',
        ]);

        LeadContact::factory()->count($contacts)->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'email' => null,
            'email_hash' => null,
            'name' => 'Jane Doe',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        return $lead->fresh(['contacts']);
    });
}

/**
 * Register the given fakes and point the capability's waterfall at them, in order.
 *
 * @param  list<object>  $providers
 */
function enrichmentWaterfallOf(ProviderCapability $capability, array $providers): EnrichmentWaterfall
{
    $registry = new EnrichmentProviderRegistry;

    foreach ($providers as $provider) {
        $registry->register($provider);
    }

    app()->instance(EnrichmentProviderRegistry::class, $registry);

    config([
        $capability->waterfallKey() => array_map(static fn (object $p): string => $p->key(), $providers),
    ]);

    return app(EnrichmentWaterfall::class);
}

function enrichmentRunFor(Team $team, Lead $lead, EnrichmentScope $scope): EnrichmentRun
{
    return app(TeamContext::class)->run($team, fn (): EnrichmentRun => EnrichmentRun::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'scope' => $scope,
        'state' => EnrichmentRunState::Running,
    ]));
}

it('stops at the first provider that finds an address', function (): void {
    $lead = leadWithEmptyContacts($this->team);

    $first = (new FakeEmailFinder('first'))->willMiss();
    $second = (new FakeEmailFinder('second'))->willFind('jane@zephyr.test');
    $third = (new FakeEmailFinder('third'))->willFind('wrong@zephyr.test');

    $waterfall = enrichmentWaterfallOf(ProviderCapability::EmailFind, [$first, $second, $third]);
    $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::Email);

    app(TeamContext::class)->run($this->team, fn () => $waterfall->run($run, $lead, $this->team));

    expect($first->callCount())->toBe(1)
        ->and($second->callCount())->toBe(1)
        // The third is never asked. That is the whole point of a waterfall: the next provider is
        // money for a second opinion nobody wanted.
        ->and($third->callCount())->toBe(0)
        ->and($lead->contacts()->first()->email)->toBe('jane@zephyr.test');
});

it('falls through an error exactly as it falls through a miss, and records them apart', function (): void {
    $lead = leadWithEmptyContacts($this->team);

    $broken = (new FakeEmailFinder('broken'))->willFail();
    $working = (new FakeEmailFinder('working'))->willFind('jane@zephyr.test');

    $waterfall = enrichmentWaterfallOf(ProviderCapability::EmailFind, [$broken, $working]);
    $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::Email);

    app(TeamContext::class)->run($this->team, fn () => $waterfall->run($run, $lead, $this->team));

    expect($lead->contacts()->first()->email)->toBe('jane@zephyr.test');

    $outcomes = EnrichmentAttempt::query()->orderBy('id')->pluck('outcome', 'provider')->all();

    // One badly behaved vendor must not take the others down with it — and an error must not be
    // filed as a miss, or an outage would look like a vendor that is bad at its job.
    expect($outcomes['broken'])->toBe(AttemptOutcome::Error)
        ->and($outcomes['working'])->toBe(AttemptOutcome::Hit);
});

it('records a miss with its cost, because a miss is what a vendor charges for', function (): void {
    $lead = leadWithEmptyContacts($this->team);

    $missed = new FakeEmailFinder('missed', cost: new CostProfile(perAttemptMicros: 200));
    $missed->willMiss();

    $waterfall = enrichmentWaterfallOf(ProviderCapability::EmailFind, [$missed]);
    $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::Email);

    app(TeamContext::class)->run($this->team, fn () => $waterfall->run($run, $lead, $this->team));

    $attempt = EnrichmentAttempt::query()->firstOrFail();

    expect($attempt->outcome)->toBe(AttemptOutcome::Miss)
        // The questions that matter commercially are all about the misses.
        ->and($attempt->cost_micros)->toBe(200)
        ->and($run->fresh()->spentMicros())->toBe(200);
});

it('stops verifying at a verdict good enough, and not at one that merely answered', function (): void {
    config(['enrichment.stop_on.email_verify' => EmailVerificationStatus::Verified->value]);

    $lead = app(TeamContext::class)->run($this->team, function (): Lead {
        $lead = Lead::factory()->create(['team_id' => $this->team->getKey()]);
        LeadContact::factory()->create([
            'team_id' => $this->team->getKey(),
            'lead_id' => $lead->getKey(),
            'email' => 'jane@zephyr.test',
            'email_verified_at' => null,
        ]);

        return $lead->fresh(['contacts']);
    });

    $cautious = (new FakeEmailVerificationProvider('cautious'))->willReturn(EmailVerificationStatus::AcceptAll);
    $certain = (new FakeEmailVerificationProvider('certain'))->willReturn(EmailVerificationStatus::Verified);

    $waterfall = enrichmentWaterfallOf(ProviderCapability::EmailVerify, [$cautious, $certain]);
    $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::Verify);

    app(TeamContext::class)->run($this->team, fn () => $waterfall->run($run, $lead, $this->team));

    // `accept_all` is an answer that tells us nothing — a catch-all domain cannot *disprove* an
    // address — so the waterfall keeps going and the stronger verdict is what is stored.
    expect($cautious->callCount())->toBe(1)
        ->and($certain->callCount())->toBe(1)
        ->and($lead->contacts()->first()->email_verification_status)->toBe(EmailVerificationStatus::Verified);
});

it('stops at accept-all when the team has said that is good enough', function (): void {
    config(['enrichment.stop_on.email_verify' => EmailVerificationStatus::AcceptAll->value]);

    $lead = app(TeamContext::class)->run($this->team, function (): Lead {
        $lead = Lead::factory()->create(['team_id' => $this->team->getKey()]);
        LeadContact::factory()->create([
            'team_id' => $this->team->getKey(),
            'lead_id' => $lead->getKey(),
            'email' => 'jane@zephyr.test',
            'email_verified_at' => null,
        ]);

        return $lead->fresh(['contacts']);
    });

    $cautious = (new FakeEmailVerificationProvider('cautious'))->willReturn(EmailVerificationStatus::AcceptAll);
    $certain = (new FakeEmailVerificationProvider('certain'))->willReturn(EmailVerificationStatus::Verified);

    $waterfall = enrichmentWaterfallOf(ProviderCapability::EmailVerify, [$cautious, $certain]);
    $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::Verify);

    app(TeamContext::class)->run($this->team, fn () => $waterfall->run($run, $lead, $this->team));

    // A config knob that genuinely changes where the money stops.
    expect($certain->callCount())->toBe(0);
});

it('skips a provider whose breaker is open, without calling it', function (): void {
    $lead = leadWithEmptyContacts($this->team);

    $paused = (new FakeEmailFinder('paused'))->willFind('never@zephyr.test');
    $working = (new FakeEmailFinder('working'))->willFind('jane@zephyr.test');

    $waterfall = enrichmentWaterfallOf(ProviderCapability::EmailFind, [$paused, $working]);

    $breaker = app('enrichment.breaker');
    foreach (range(1, (int) config('enrichment.circuit_breaker.threshold', 5)) as $ignored) {
        $breaker->recordFailure('paused');
    }

    $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::Email);

    app(TeamContext::class)->run($this->team, fn () => $waterfall->run($run, $lead, $this->team));

    expect($paused->callCount())->toBe(0)
        ->and($lead->contacts()->first()->email)->toBe('jane@zephyr.test');

    $skip = EnrichmentAttempt::query()->where('provider', 'paused')->firstOrFail();

    // Recorded, and recorded as a **skip**: it cost nothing and says nothing about the vendor's
    // hit rate, so folding it in with a miss would libel a provider that was never asked.
    expect($skip->outcome)->toBe(AttemptOutcome::SkippedCircuitOpen)
        ->and($skip->cost_micros)->toBe(0)
        ->and($skip->outcome->countsTowardsHitRate())->toBeFalse();
});

it('skips a rate-limited provider and tries the next rather than waiting', function (): void {
    config(['enrichment.providers.limited.rate_limit' => 1]);

    $lead = leadWithEmptyContacts($this->team, contacts: 2);

    $limited = (new FakeEmailFinder('limited'))->willFind('one@zephyr.test')->willFind('two@zephyr.test');
    $backup = (new FakeEmailFinder('backup'))->willFind('three@zephyr.test')->willFind('four@zephyr.test');

    $waterfall = enrichmentWaterfallOf(ProviderCapability::EmailFind, [$limited, $backup]);
    $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::Email);

    app(TeamContext::class)->run($this->team, fn () => $waterfall->run($run, $lead, $this->team));

    // One call allowed this minute, so the second contact falls through to the backup rather than
    // the job sleeping: holding a worker for a minute to save one call is a bad trade.
    expect($limited->callCount())->toBe(1)
        ->and($backup->callCount())->toBe(1)
        ->and(EnrichmentAttempt::query()->where('outcome', AttemptOutcome::SkippedRateLimited)->count())->toBe(1);
});

it('does not pay to ask a question with nothing to go on', function (): void {
    $lead = app(TeamContext::class)->run($this->team, function (): Lead {
        // No website, no company number, and a contact with no name: nothing a finder could key on.
        $lead = Lead::factory()->create([
            'team_id' => $this->team->getKey(),
            'website' => null,
            'company_number' => null,
        ]);

        LeadContact::factory()->create([
            'team_id' => $this->team->getKey(),
            'lead_id' => $lead->getKey(),
            'email' => null,
            'email_hash' => null,
            'name' => null,
            'first_name' => null,
            'last_name' => null,
        ]);

        return $lead->fresh(['contacts']);
    });

    $finder = (new FakeEmailFinder('finder'))->willFind('nobody@nowhere.test');

    $waterfall = enrichmentWaterfallOf(ProviderCapability::EmailFind, [$finder]);
    $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::Email);

    app(TeamContext::class)->run($this->team, fn () => $waterfall->run($run, $lead, $this->team));

    // Checked before the call, not after the answer: a vendor that bills per attempt charges for a
    // question we could have known was unanswerable.
    expect($finder->callCount())->toBe(0)
        ->and(EnrichmentAttempt::query()->where('outcome', AttemptOutcome::SkippedUnanswerable)->count())->toBe(1);
});

it('creates contacts from the people it finds, honouring the limit', function (): void {
    config(['enrichment.max_people_per_run' => 2]);

    $lead = app(TeamContext::class)->run($this->team, fn (): Lead => Lead::factory()->create([
        'team_id' => $this->team->getKey(),
        'website' => 'https://zephyr.test',
    ])->fresh(['contacts']));

    $finder = (new FakePersonFinder('people'))->willFind([
        new FoundPerson(firstName: 'Jane', lastName: 'Doe', title: 'Operations Director'),
        new FoundPerson(firstName: 'Raj', lastName: 'Patel', title: 'Head of Logistics'),
        new FoundPerson(firstName: 'Sam', lastName: 'Lee', title: 'CFO'),
        // A role inbox dressed as a person. Not a decision-maker, and not a hit.
        new FoundPerson(fullName: null, title: 'Sales Team'),
    ]);

    $waterfall = enrichmentWaterfallOf(ProviderCapability::PeopleFind, [$finder]);
    $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::People);

    $summary = app(TeamContext::class)->run($this->team, fn (): array => $waterfall->run($run, $lead, $this->team));

    // The limit is the money. A fake that ignored it would make this test worthless, which is why
    // `FakePersonFinder` honours it like a real vendor would.
    expect($summary['contacts_created'])->toBe(2)
        ->and($lead->contacts()->count())->toBe(2)
        ->and($lead->contacts()->pluck('name')->all())->toContain('Jane Doe');
});

it('does not duplicate a person a second provider returns', function (): void {
    $lead = app(TeamContext::class)->run($this->team, fn (): Lead => Lead::factory()->create([
        'team_id' => $this->team->getKey(),
        'website' => 'https://zephyr.test',
    ])->fresh(['contacts']));

    $finder = (new FakePersonFinder('people'))->willFind([
        new FoundPerson(firstName: 'Jane', lastName: 'Doe', title: 'Operations Director', email: 'Jane@Zephyr.test'),
    ]);

    $waterfall = enrichmentWaterfallOf(ProviderCapability::PeopleFind, [$finder]);

    foreach (range(1, 2) as $ignored) {
        $finder->willFind([
            // The same person, with the address spelled differently — which is exactly what a
            // second vendor returns.
            new FoundPerson(firstName: 'Jane', lastName: 'Doe', title: 'Ops Director', email: 'jane@zephyr.test'),
        ]);

        $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::People);
        app(TeamContext::class)->run($this->team, fn () => $waterfall->run($run, $lead->fresh(['contacts']), $this->team));
    }

    // De-duplicated on the address's **identity**, so a differently-cased address is the same
    // person rather than a second contact.
    expect($lead->contacts()->count())->toBe(1);
});

it('normalises a found number to E.164 and classifies it', function (): void {
    $lead = leadWithEmptyContacts($this->team);

    // **Not** an 07700 900xxx number: that is Ofcom's reserved-for-drama range, and
    // libphonenumber rightly refuses to call it valid — so it would exercise the refusal path
    // rather than the one this test is about. A real trap, because those are the numbers
    // documentation tells you to use.
    $finder = (new FakePhoneFinder('phones'))->willFind('07912 345678', PhoneType::Mobile);

    $waterfall = enrichmentWaterfallOf(ProviderCapability::PhoneFind, [$finder]);
    $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::Phone);

    app(TeamContext::class)->run($this->team, fn () => $waterfall->run($run, $lead, $this->team));

    $contact = $lead->contacts()->first();

    // A national number is not a country code glued to the digits: the leading zero goes.
    expect($contact->phone_e164)->toBe('+447912345678')
        ->and($contact->phone_type)->toBe(PhoneType::Mobile)
        ->and($contact->phone_source)->toBe('phones')
        // And the hash is the **digits**, so a suppression entry still matches it.
        ->and($contact->phone_hash)->not->toBeNull();
});

it('keeps a number it cannot normalise, without a dialable form', function (): void {
    $lead = leadWithEmptyContacts($this->team);

    $finder = (new FakePhoneFinder('phones'))->willFind('555-0100', PhoneType::Unknown);

    $waterfall = enrichmentWaterfallOf(ProviderCapability::PhoneFind, [$finder]);
    $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::Phone);

    app(TeamContext::class)->run($this->team, fn () => $waterfall->run($run, $lead, $this->team));

    $contact = $lead->contacts()->first();

    // The raw value is shown and no tap-to-call is offered. A half-normalised number dials
    // somebody else, which is the one failure here that reaches a stranger.
    expect($contact->phone)->toBe('555-0100')
        ->and($contact->phone_e164)->toBeNull();
});

it('marks a contact done once it has been looked at', function (): void {
    $lead = leadWithEmptyContacts($this->team);

    $waterfall = enrichmentWaterfallOf(ProviderCapability::EmailFind, [
        (new FakeEmailFinder('finder'))->willFind('jane@zephyr.test'),
    ]);

    $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::Email);

    app(TeamContext::class)->run($this->team, fn () => $waterfall->run($run, $lead, $this->team));

    expect($lead->contacts()->first()->enrichment_state)->toBe(ContactEnrichmentState::Done);
});

it('does not re-verify an address checked inside the window', function (): void {
    config(['enrichment.reenrich_after_days' => 30]);

    $lead = app(TeamContext::class)->run($this->team, function (): Lead {
        $lead = Lead::factory()->create(['team_id' => $this->team->getKey()]);
        LeadContact::factory()->create([
            'team_id' => $this->team->getKey(),
            'lead_id' => $lead->getKey(),
            'email' => 'jane@zephyr.test',
            'email_verified_at' => now()->subDays(3),
        ]);

        return $lead->fresh(['contacts']);
    });

    $verifier = (new FakeEmailVerificationProvider('verifier'))->willReturn(EmailVerificationStatus::Verified);

    $waterfall = enrichmentWaterfallOf(ProviderCapability::EmailVerify, [$verifier]);
    $run = enrichmentRunFor($this->team, $lead, EnrichmentScope::Verify);

    app(TeamContext::class)->run($this->team, fn () => $waterfall->run($run, $lead, $this->team));

    // A mailbox that existed last week usually still does. Re-verifying a team's whole list
    // nightly would be a bill for confirming what we already knew.
    expect($verifier->callCount())->toBe(0);
});

it('keeps one team’s attempts out of another’s reach', function (): void {
    $theirs = Team::factory()->create();
    $lead = leadWithEmptyContacts($theirs);

    $waterfall = enrichmentWaterfallOf(ProviderCapability::EmailFind, [
        (new FakeEmailFinder('finder'))->willFind('jane@theirs.test'),
    ]);

    $run = enrichmentRunFor($theirs, $lead, EnrichmentScope::Email);
    app(TeamContext::class)->run($theirs, fn () => $waterfall->run($run, $lead, $theirs));

    $visible = app(TeamContext::class)->run($this->team, fn (): int => EnrichmentAttempt::query()->count());

    expect($visible)->toBe(0)
        ->and(EnrichmentAttempt::query()->withoutGlobalScopes()->count())->toBeGreaterThan(0);
});
