<?php

declare(strict_types=1);

use App\Modules\Enrichment\Contracts\EmailFinder;
use App\Modules\Enrichment\Contracts\EmailVerificationProvider;
use App\Modules\Enrichment\Data\CostProfile;
use App\Modules\Enrichment\Data\EnrichmentResult;
use App\Modules\Enrichment\Data\PersonQuery;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\Enrichment\Services\EnrichmentProviderRegistry;
use App\Modules\Enrichment\Services\ZeroBounceEmailVerifier;
use App\Modules\Enrichment\Testing\FakeEmailFinder;
use App\Modules\Enrichment\Testing\FakeEmailVerificationProvider;
use App\Modules\Enrichment\Testing\FakePersonFinder;
use App\Modules\Enrichment\Testing\FakePhoneFinder;

/*
 * Which vendors get tried, for which question, in which order (PL2).
 *
 * The registry's three rules are each here because of a way the two registries before it have
 * failed: a mis-registered provider that blew up inside a queued job, a config typo that silently
 * removed a source, and an unconfigured vendor that threw rather than standing aside.
 */
it('returns a capability’s providers in the order config names them', function (): void {
    config(['enrichment.waterfall.email_verify' => ['second', 'first']]);

    $registry = new EnrichmentProviderRegistry;
    $registry->register(new FakeEmailVerificationProvider('first'));
    $registry->register(new FakeEmailVerificationProvider('second'));

    $keys = array_map(
        static fn (EmailVerificationProvider $p): string => $p->key(),
        $registry->for(ProviderCapability::EmailVerify),
    );

    // Registration order is an accident of a service provider; **config order is the decision** —
    // it is a commercial judgement about price and hit rate, and it must not be a line of PHP.
    expect($keys)->toBe(['second', 'first']);
});

it('leaves a disabled provider out of the waterfall rather than failing in it', function (): void {
    config(['enrichment.waterfall.email_verify' => ['off', 'on']]);

    $registry = new EnrichmentProviderRegistry;
    $registry->register(new FakeEmailVerificationProvider('off', enabled: false));
    $registry->register(new FakeEmailVerificationProvider('on'));

    $keys = array_map(
        static fn (EmailVerificationProvider $p): string => $p->key(),
        $registry->for(ProviderCapability::EmailVerify),
    );

    expect($keys)->toBe(['on'])
        // Still registered, though: the admin panel lists every provider, because an unconfigured
        // one is exactly what somebody is looking for when nothing is being found.
        ->and($registry->get('off'))->not->toBeNull()
        ->and($registry->all())->toHaveCount(2);
});

it('refuses a provider registered under the wrong capability, at registration', function (): void {
    $registry = new EnrichmentProviderRegistry;

    // A finder that claims it verifies. Caught here — boot time, a test, CI — rather than inside a
    // queued job, against a real lead, after the vendor has already been paid.
    $liar = new class implements EmailFinder
    {
        public function findEmail(PersonQuery $query): EnrichmentResult
        {
            return EnrichmentResult::miss($this->key());
        }

        public function key(): string
        {
            return 'liar';
        }

        public function capability(): ProviderCapability
        {
            return ProviderCapability::EmailVerify;
        }

        public function describesCost(): CostProfile
        {
            return CostProfile::free();
        }

        public function isEnabled(): bool
        {
            return true;
        }
    };

    expect(fn () => $registry->register($liar))->toThrow(InvalidArgumentException::class);
});

it('reports a waterfall key nothing has registered, without taking the capability down', function (): void {
    config(['enrichment.waterfall.email_verify' => ['typo', 'real']]);

    $registry = new EnrichmentProviderRegistry;
    $registry->register(new FakeEmailVerificationProvider('real'));

    $keys = array_map(
        static fn (EmailVerificationProvider $p): string => $p->key(),
        $registry->for(ProviderCapability::EmailVerify),
    );

    // One bad line must not take a whole capability down — and it must not sit there unnoticed.
    expect($keys)->toBe(['real'])
        ->and($registry->unknownIn(ProviderCapability::EmailVerify))->toBe(['typo']);
});

it('has no unknown provider in any shipped waterfall', function (): void {
    $registry = app(EnrichmentProviderRegistry::class);

    foreach (ProviderCapability::cases() as $capability) {
        // The guard that turns a typo in `config/enrichment.php` into a failing suite rather than
        // a vendor that silently never runs.
        expect($registry->unknownIn($capability))->toBe([]);
    }
});

it('registers the reference verifier, disabled until a vendor decision exists', function (): void {
    $registry = app(EnrichmentProviderRegistry::class);

    $zerobounce = $registry->get(ZeroBounceEmailVerifier::KEY);

    expect($zerobounce)->toBeInstanceOf(ZeroBounceEmailVerifier::class)
        ->and($zerobounce->capability())->toBe(ProviderCapability::EmailVerify)
        // ADR 0027 §2: no vendor is enabled without written confirmation that embedding its data
        // in a product our customers use is permitted. The default has to be off, in the shipped
        // config, and this is what keeps it off.
        ->and($zerobounce->isEnabled())->toBeFalse()
        ->and(app(EnrichmentProviderRegistry::class)->for(ProviderCapability::EmailVerify))->toBe([]);
});

it('knows which contract each capability demands', function (): void {
    foreach (ProviderCapability::cases() as $capability) {
        expect(interface_exists($capability->contract()))->toBeTrue()
            ->and($capability->waterfallKey())->toBe('enrichment.waterfall.'.$capability->value);
    }
});

it('gives every fake a cost profile that costs nothing', function (): void {
    // A fake that declared a price would make a metering test pass against money nobody spent.
    foreach ([new FakeEmailFinder, new FakePhoneFinder, new FakePersonFinder, new FakeEmailVerificationProvider] as $fake) {
        expect($fake->describesCost()->worstCaseMicros())->toBe(0);
    }
});

it('reads a cost profile the way a vendor bills', function (): void {
    $perHit = new CostProfile(perSuccessMicros: 500);
    $perLookup = new CostProfile(perAttemptMicros: 200);

    expect($perHit->forOutcome(true))->toBe(500)
        // A finder that bills only for a hit is cheap to try and free to fail, which is what makes
        // a long waterfall affordable.
        ->and($perHit->forOutcome(false))->toBe(0)
        // A verifier that bills per lookup charges for every "no", and PL4's margin figures are
        // wrong if that is not counted.
        ->and($perLookup->forOutcome(false))->toBe(200)
        ->and($perLookup->forOutcome(true))->toBe(200)
        // An estimate shown to a member has to be the ceiling, or it is the figure they will quote
        // back when the bill is larger.
        ->and((new CostProfile(perSuccessMicros: 500, perAttemptMicros: 200))->worstCaseMicros())->toBe(700);
});
