<?php

declare(strict_types=1);

use App\Modules\Enrichment\Enums\PhoneType;
use App\Modules\Enrichment\Services\PhoneNormaliser;
use Tests\TestCase;

/*
 * Turning a vendor's phone number into something a member can tap (PL3).
 *
 * The reason this is a library and not a regex, in one line: a national number is not a country
 * code glued to the digits, and getting it wrong does not produce an invalid number — it produces
 * a **valid number belonging to somebody else**. That is the one failure in this phase that
 * reaches a stranger.
 */
uses(TestCase::class);

beforeEach(function (): void {
    $this->phones = app(PhoneNormaliser::class);
});

it('drops the national trunk prefix rather than prepending a country code', function (): void {
    // The whole point. `44` + `07912345678` would be `+4407912345678`, which is not a number.
    expect($this->phones->toE164('07912 345678', 'GB'))->toBe('+447912345678')
        ->and($this->phones->toE164('020 7946 0000', 'GB'))->toBe('+442079460000')
        ->and($this->phones->toE164('050 123 4567', 'AE'))->toBe('+971501234567');
});

it('leaves an international number alone, whatever country it is given', function (): void {
    // The region is only consulted for a number that is not already international, so a wrong
    // country cannot corrupt a `+`-prefixed value — which matters because the country we hold is
    // the **lead's**, and a person's number need not be in their company's country.
    expect($this->phones->toE164('+447912345678'))->toBe('+447912345678')
        ->and($this->phones->toE164('+447912345678', 'AE'))->toBe('+447912345678');
});

it('refuses a number it cannot read, rather than guessing', function (): void {
    // Null means the screen shows the raw value and offers no tap-to-call. Better than a number
    // that dials somebody else.
    expect($this->phones->toE164('555-0100', 'GB'))->toBeNull()
        ->and($this->phones->toE164('not a number', 'GB'))->toBeNull()
        ->and($this->phones->toE164(''))->toBeNull()
        // A national number with no country to read it against is unparseable, and inventing a
        // default would be inventing a country code.
        ->and($this->phones->toE164('07912 345678'))->toBeNull();
});

it('refuses a number reserved for fiction', function (): void {
    // Ofcom reserves 07700 900xxx for drama, and libphonenumber marks the range invalid. Worth a
    // test because those are exactly the numbers documentation tells you to use — PL3's own first
    // fixture was one, and it looked like a bug in the normaliser.
    expect($this->phones->toE164('07700 900123', 'GB'))->toBeNull()
        ->and($this->phones->isValid('07700 900123', 'GB'))->toBeFalse();
});

it('tells a mobile from a switchboard', function (): void {
    expect($this->phones->classify('07912 345678', 'GB'))->toBe(PhoneType::Mobile)
        // A company's main line. Read as a switchboard, which is the **cost**-cautious direction:
        // masking it would charge a member to reveal a number printed on the homepage.
        ->and($this->phones->classify('020 7946 0000', 'GB'))->toBe(PhoneType::Switchboard);
});

it('treats a number of unknown kind as a person’s', function (): void {
    // The **compliance**-cautious direction, and deliberately the opposite default from the one
    // above: a mobile is personal data and a switchboard is not, so an unknown kind errs towards
    // the person. Both defaults are chosen; neither is an accident.
    expect($this->phones->classify('not a number', 'GB'))->toBe(PhoneType::Unknown)
        ->and(PhoneType::Unknown->isPersonal())->toBeTrue();
});

it('offers a messaging link only for a mobile', function (): void {
    expect(PhoneType::Mobile->acceptsMessaging())->toBeTrue()
        // A WhatsApp link on a landline opens a chat that can never be delivered.
        ->and(PhoneType::Direct->acceptsMessaging())->toBeFalse()
        ->and(PhoneType::Switchboard->acceptsMessaging())->toBeFalse()
        ->and(PhoneType::Unknown->acceptsMessaging())->toBeFalse();
});
