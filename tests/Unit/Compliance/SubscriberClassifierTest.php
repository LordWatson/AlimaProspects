<?php

declare(strict_types=1);

use App\Modules\Compliance\Enums\PecrSubscriberType;
use App\Modules\Compliance\Enums\TpsRegister;
use App\Modules\Compliance\Services\SubscriberClassifier;
use App\Modules\LeadSourcing\Models\Lead;

/**
 * PECR's corporate/individual distinction (PL5, docs/compliance.md §3.15).
 *
 * The table is the test. The one rule worth stating out loud is that **only one path reaches
 * `Corporate`** — a UK lead with a registration number and a company type naming a body corporate
 * — because every other outcome warns, and a classifier that guessed "corporate" from a
 * business-looking address would be telling a customer it is fine to email a sole trader.
 */
it('classifies a UK subscriber from what the lead already records', function (
    ?string $country,
    ?string $number,
    ?string $type,
    PecrSubscriberType $expected,
): void {
    $lead = new Lead;
    $lead->country = $country;
    $lead->company_number = $number;
    $lead->company_type = $type;

    expect((new SubscriberClassifier)->classify($lead))->toBe($expected);
})->with([
    'a limited company' => ['GB', '01234567', 'ltd', PecrSubscriberType::Corporate],
    'the register\'s own long key' => ['GB', '01234567', 'private-limited-guarant-nsc', PecrSubscriberType::Corporate],
    'a PLC' => ['UK', '01234567', 'plc', PecrSubscriberType::Corporate],
    'an LLP' => ['GB', 'OC301234', 'llp', PecrSubscriberType::Corporate],
    'words rather than a key' => ['GB', '01234567', 'Private Limited Company', PecrSubscriberType::Corporate],

    // A partnership is the partners, registered or not — and `limited-partnership` would match
    // `limited` if the individual list were not checked first, which is the whole reason it is.
    'a limited partnership' => ['GB', 'LP012345', 'limited-partnership', PecrSubscriberType::Individual],
    'a Scottish partnership' => ['GB', 'SL012345', 'scottish-partnership', PecrSubscriberType::Individual],
    'a sole trader' => ['GB', null, 'sole-trader', PecrSubscriberType::Individual],

    // No registration number is not evidence of a sole trader: it is most of what a maps provider
    // returns, and asserting a subscriber type on it would be inventing a legal fact.
    'a maps result with no number' => ['GB', null, null, PecrSubscriberType::Unknown],
    'a number but an unrecognised type' => ['GB', '01234567', 'something-new', PecrSubscriberType::Unknown],

    // PECR is a UK instrument. A Dubai company is not an unknown kind of PECR subscriber; the
    // question does not arise, and the UAE answer is the prior-consent restriction instead.
    'a UAE lead' => ['AE', '1234', 'ltd', PecrSubscriberType::Unknown],
    'a country we take no position on' => ['FR', '1234', 'ltd', PecrSubscriberType::Unknown],
    'no country at all' => [null, '01234567', 'ltd', PecrSubscriberType::Unknown],
]);

it('sends a corporate subscriber to CTPS and everyone else to TPS', function (): void {
    expect(PecrSubscriberType::Corporate->register())->toBe(TpsRegister::Ctps)
        ->and(PecrSubscriberType::Individual->register())->toBe(TpsRegister::Tps)
        // The cautious question rather than the convenient one: TPS covers the wider set.
        ->and(PecrSubscriberType::Unknown->register())->toBe(TpsRegister::Tps);
});

it('treats an unknown subscriber as needing prior consent', function (): void {
    // "May we proceed without consent" has one honest answer when we do not know.
    expect(PecrSubscriberType::Unknown->needsPriorConsent())->toBeTrue()
        ->and(PecrSubscriberType::Individual->needsPriorConsent())->toBeTrue()
        ->and(PecrSubscriberType::Corporate->needsPriorConsent())->toBeFalse();
});
