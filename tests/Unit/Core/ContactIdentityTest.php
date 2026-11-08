<?php

declare(strict_types=1);

use App\Modules\Core\Support\ContactIdentity;
use App\Modules\Outreach\Enums\SuppressionType;
use Tests\TestCase;

/*
 * The one definition of "the same email address, the same number, the same domain" (PL2).
 *
 * The test that matters most here is the last one. Two tables hash an identity —
 * `outreach_suppressions`, which records who asked to be left alone, and `lead_contacts`, which
 * from PL2 records what a vendor found — and if they normalised differently the failure would not
 * be a missed index. It would be an email sent to somebody who opted out, and nothing would show
 * it had happened.
 */
uses(TestCase::class);

it('lower-cases and trims an address, and refuses what is not one', function (): void {
    expect(ContactIdentity::email('  Jane.Doe@Acme.COM '))->toBe('jane.doe@acme.com')
        ->and(ContactIdentity::email('jane@acme.com'))->toBe('jane@acme.com')
        ->and(ContactIdentity::email('not-an-address'))->toBeNull()
        ->and(ContactIdentity::email(''))->toBeNull()
        ->and(ContactIdentity::email('jane@'))->toBeNull();
});

it('leaves the local part alone', function (): void {
    // Stripping dots or `+tags` is one provider's routing rule applied to every provider, and the
    // addresses it wrongly merged would be two different people.
    expect(ContactIdentity::email('jane.doe@acme.com'))->not->toBe(ContactIdentity::email('janedoe@acme.com'))
        ->and(ContactIdentity::email('jane+leads@acme.com'))->toBe('jane+leads@acme.com');
});

it('reduces a domain to its registrable form, however it arrives', function (): void {
    expect(ContactIdentity::domain('https://www.Acme.com/contact'))->toBe('acme.com')
        ->and(ContactIdentity::domain('acme.com/contact'))->toBe('acme.com')
        ->and(ContactIdentity::domain('WWW.ACME.CO.UK'))->toBe('acme.co.uk')
        // A whole address where a domain was meant: take the domain rather than refuse.
        ->and(ContactIdentity::domain('jane@acme.com'))->toBe('acme.com')
        // A bare label is not a domain and would match nothing.
        ->and(ContactIdentity::domain('acme'))->toBeNull()
        ->and(ContactIdentity::domain('acme com'))->toBeNull();
});

it('reduces a phone number to comparable digits', function (): void {
    expect(ContactIdentity::phone('+44 7700 900123'))->toBe('447700900123')
        ->and(ContactIdentity::phone('00447700900123'))->toBe('447700900123')
        ->and(ContactIdentity::phone('(0770) 090-0123'))->toBe('07700900123')
        // Too short to be a number — a typo, not a contact.
        ->and(ContactIdentity::phone('12345'))->toBeNull();
});

it('writes the same number four ways to the same identity', function (): void {
    $forms = ['+447700900123', '00447700900123', '44 7700 900123', '44-7700-900123'];

    $hashes = array_unique(array_map(
        static fn (string $form): string => ContactIdentity::hash((string) ContactIdentity::phone($form)),
        $forms,
    ));

    expect($hashes)->toHaveCount(1);
});

it('shows enough of an address to recognise and not enough to use', function (): void {
    expect(ContactIdentity::maskEmail('jane.doe@acme.com'))->toBe('j***@acme.com')
        // The domain survives: it is the company, not the private half, and it is usually already
        // on the lead. A member comparing two masked addresses needs something to tell them apart.
        ->and(ContactIdentity::maskEmail('jane.doe@acme.com'))->toContain('acme.com')
        ->and(ContactIdentity::maskEmail('jane.doe@acme.com'))->not->toContain('doe');
});

it('shows a number the way a card is shown', function (): void {
    expect(ContactIdentity::maskPhone('447700904821'))->toBe('***4821');
});

it('hashes deterministically and without a salt', function (): void {
    // Unsalted on purpose: the whole point is to recognise the same identity arriving from
    // somewhere else later, which a salted hash cannot do.
    expect(ContactIdentity::hash('jane@acme.com'))
        ->toBe(ContactIdentity::hash('jane@acme.com'))
        ->and(ContactIdentity::hash('jane@acme.com'))
        ->toBe(hash('sha256', 'jane@acme.com'));
});

it('agrees with the suppression list about every identity', function (): void {
    $emails = ['Jane.Doe@Acme.com', ' bob@example.test ', 'x@y.co.uk'];
    $phones = ['+44 7700 900123', '00447700900123', '07700 900123'];
    $domains = ['https://www.Acme.com/contact', 'acme.com', 'jane@acme.com'];

    foreach ($emails as $email) {
        expect(SuppressionType::Email->normalise($email))->toBe(ContactIdentity::email($email));
    }

    foreach ($phones as $phone) {
        expect(SuppressionType::Phone->normalise($phone))->toBe(ContactIdentity::phone($phone));
    }

    foreach ($domains as $domain) {
        expect(SuppressionType::Domain->normalise($domain))->toBe(ContactIdentity::domain($domain));
    }

    // And the masking, so a suppression entry and a masked contact read the same on screen.
    expect(SuppressionType::Email->preview('jane.doe@acme.com'))
        ->toBe(ContactIdentity::maskEmail('jane.doe@acme.com'))
        ->and(SuppressionType::Phone->preview('447700904821'))
        ->toBe(ContactIdentity::maskPhone('447700904821'));
});
