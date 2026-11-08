<?php

declare(strict_types=1);

use App\Modules\Compliance\Contracts\TpsScreeningProvider;
use App\Modules\Compliance\Enums\TpsRegister;
use App\Modules\Compliance\Models\TpsCheck;
use App\Modules\Compliance\Services\TpsScreener;
use App\Modules\Compliance\Services\UnlicensedTpsScreening;
use App\Modules\Core\Enums\TpsStatus;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\ContactIdentity;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Contracts\ContactNumberScreener;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComplianceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * TPS/CTPS screening (PL5, docs/compliance.md §3.16).
 *
 * The rule every one of these tests is really about: **unknown is not clear**. No licensed adapter
 * ships, so the shipped behaviour is that every number stays `unchecked` — and the one thing that
 * must never happen is a build with no licence quietly reporting every number as callable.
 */
it('binds the real screener over Enrichment\'s no-op, and the unlicensed provider', function (): void {
    expect(app(ContactNumberScreener::class))->toBeInstanceOf(TpsScreener::class)
        ->and(app(TpsScreeningProvider::class))->toBeInstanceOf(UnlicensedTpsScreening::class)
        ->and(app(TpsScreeningProvider::class)->isAvailable())->toBeFalse();
});

it('leaves a number unchecked when no provider can answer — never clear', function (): void {
    [, $team] = Fixtures::team();
    $contact = ukContact($team);

    app(ContactNumberScreener::class)->screen($team, $contact);

    expect($contact->refresh()->tps_status)->toBe(TpsStatus::Unchecked)
        ->and(TpsCheck::query()->count())->toBe(0);
});

it('stamps a verdict on the contact and caches it platform-wide', function (): void {
    fakeProvider(TpsStatus::Registered);

    [, $team] = Fixtures::team();
    $contact = ukContact($team);

    app(ContactNumberScreener::class)->screen($team, $contact);

    $contact->refresh();

    expect($contact->tps_status)->toBe(TpsStatus::Registered)
        ->and($contact->tps_checked_at)->not->toBeNull();

    $check = TpsCheck::query()->sole();

    expect($check->result)->toBe(TpsStatus::Registered)
        // A hash, never the number: this row says "this identity is registered", not "here is a
        // phone book".
        ->and($check->phone_hash)->toBe(ContactIdentity::hash('442079460000'))
        // No registration number and no corporate type on the lead, so the subscriber is unknown
        // and TPS — the wider register — is the one asked.
        ->and($check->register)->toBe(TpsRegister::Tps);
});

it('asks CTPS for a corporate subscriber', function (): void {
    fakeProvider(TpsStatus::Clear);

    [, $team] = Fixtures::team();
    $contact = ukContact($team, ['company_number' => '01234567', 'company_type' => 'ltd']);

    app(ContactNumberScreener::class)->screen($team, $contact);

    expect(TpsCheck::query()->sole()->register)->toBe(TpsRegister::Ctps);
});

it('serves a second team from the cache rather than paying twice', function (): void {
    $provider = fakeProvider(TpsStatus::Registered);

    [, $first] = Fixtures::team();
    [, $second] = Fixtures::team();

    app(ContactNumberScreener::class)->screen($first, ukContact($first));
    app(ContactNumberScreener::class)->screen($second, ukContact($second));

    expect($provider->calls)->toBe(1)
        ->and(TpsCheck::query()->count())->toBe(1);
});

it('re-screens a verdict that has gone stale, and keeps the old one if nobody answers', function (): void {
    config()->set('compliance.tps.recheck_days', 28);

    [, $team] = Fixtures::team();
    $contact = ukContact($team);

    TpsCheck::query()->create([
        'phone_hash' => ContactIdentity::hash('442079460000'),
        'register' => TpsRegister::Tps->value,
        'result' => TpsStatus::Clear->value,
        'provider' => 'fixture',
        'checked_at' => now()->subDays(40),
    ]);

    // No provider: the stale verdict stands rather than being discarded, because an expired row
    // with nothing in its place reads as "never checked".
    app(ContactNumberScreener::class)->screen($team, $contact);

    expect($contact->refresh()->tps_status)->toBe(TpsStatus::Clear);

    fakeProvider(TpsStatus::Registered);

    app(ContactNumberScreener::class)->screen($team, $contact);

    expect($contact->refresh()->tps_status)->toBe(TpsStatus::Registered)
        ->and(TpsCheck::query()->sole()->provider)->toBe('fake');
});

it('marks a non-UK number as outside the registers rather than clear', function (): void {
    $provider = fakeProvider(TpsStatus::Clear);

    [, $team] = Fixtures::team();
    $contact = ukContact($team, ['country' => 'AE'], phone: '04 123 4567');

    app(ContactNumberScreener::class)->screen($team, $contact);

    expect($contact->refresh()->tps_status)->toBe(TpsStatus::NotApplicable)
        ->and($provider->calls)->toBe(0);
});

it('leaves a number it cannot place alone', function (): void {
    fakeProvider(TpsStatus::Clear);

    [, $team] = Fixtures::team();
    // A bare national number and no country on the lead: it could be anywhere, so it is neither
    // screened nor declared outside the registers.
    $contact = ukContact($team, ['country' => null], phone: '020 7946 0000');

    app(ContactNumberScreener::class)->screen($team, $contact);

    expect($contact->refresh()->tps_status)->toBe(TpsStatus::Unchecked);
});

it('never fails the reveal it runs after', function (): void {
    app()->bind(TpsScreeningProvider::class, fn (): TpsScreeningProvider => new class implements TpsScreeningProvider
    {
        public function key(): string
        {
            return 'broken';
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function screen(string $digits, TpsRegister $register): ?TpsStatus
        {
            throw new RuntimeException('register is down');
        }
    });

    [, $team] = Fixtures::team();
    $contact = ukContact($team);

    // The member has already been charged by the time this runs; a slow or broken register must
    // not cost them the credit.
    app(ContactNumberScreener::class)->screen($team, $contact);

    expect($contact->refresh()->tps_status)->toBe(TpsStatus::Unchecked);
});

it('only withdraws the dial link for a registered number', function (): void {
    expect(TpsStatus::Registered->allowsDialLink())->toBeFalse()
        // Hiding every unscreened number would hide every number until a licence exists. The badge
        // is how a member is told; the decision stays theirs.
        ->and(TpsStatus::Unchecked->allowsDialLink())->toBeTrue()
        ->and(TpsStatus::Clear->allowsDialLink())->toBeTrue()
        ->and(TpsStatus::NotApplicable->allowsDialLink())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A licensed-looking provider that always gives the same answer and counts how often it was asked.
 */
function fakeProvider(TpsStatus $answer): object
{
    $provider = new class($answer) implements TpsScreeningProvider
    {
        public int $calls = 0;

        public function __construct(private readonly TpsStatus $answer) {}

        public function key(): string
        {
            return 'fake';
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function screen(string $digits, TpsRegister $register): ?TpsStatus
        {
            $this->calls++;

            return $this->answer;
        }
    };

    app()->instance(TpsScreeningProvider::class, $provider);
    app()->forgetInstance(TpsScreener::class);
    app()->forgetInstance(ContactNumberScreener::class);

    return $provider;
}

/**
 * @param  array<string, mixed>  $lead
 */
function ukContact(Team $team, array $lead = [], string $phone = '+44 20 7946 0000'): LeadContact
{
    return app(TeamContext::class)->run($team, function () use ($team, $lead, $phone): LeadContact {
        $model = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'country' => 'GB',
            'company_number' => null,
            'company_type' => null,
            ...$lead,
        ]);

        return LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $model->getKey(),
            'phone' => $phone,
        ]);
    });
}
