<?php

declare(strict_types=1);

use App\Modules\Outreach\Enums\SuppressionType;

/**
 * How a candidate identity is normalised before it is hashed.
 *
 * This is the quietest and most consequential code in the module: a suppression list that misses
 * `John@Acme.com ` having stored `john@acme.com` is not a suppression list. Every case here is one
 * a real paste produces.
 */
it('normalises an email to one comparable form', function (string $input, ?string $expected): void {
    expect(SuppressionType::Email->normalise($input))->toBe($expected);
})->with([
    'plain' => ['dana@acme.test', 'dana@acme.test'],
    'cased and padded' => ['  Dana@Acme.TEST ', 'dana@acme.test'],
    'not an address' => ['dana at acme', null],
    'empty' => ['   ', null],
]);

it('normalises a domain however it was pasted', function (string $input, ?string $expected): void {
    expect(SuppressionType::Domain->normalise($input))->toBe($expected);
})->with([
    'bare' => ['acme.test', 'acme.test'],
    'with www' => ['WWW.Acme.Test', 'acme.test'],
    // The paste a member actually makes: a whole URL out of the address bar.
    'a url' => ['https://www.acme.test/contact-us?utm=1', 'acme.test'],
    'no scheme, with a path' => ['acme.test/contact', 'acme.test'],
    // A whole address where a domain was meant: taken rather than refused.
    'an address' => ['dana@Acme.test', 'acme.test'],
    'subdomain kept' => ['mail.acme.test', 'mail.acme.test'],
    // A bare label would suppress nothing, and the member should be told rather than reassured.
    'a bare label' => ['acme', null],
    'nonsense' => ['not a domain', null],
]);

it('normalises a phone number to digits, so a format cannot hide it', function (string $input, ?string $expected): void {
    expect(SuppressionType::Phone->normalise($input))->toBe($expected);
})->with([
    'spaced international' => ['+44 20 7946 0000', '442079460000'],
    // `00` and `+` are the same prefix, so the same number.
    'double-zero prefix' => ['00442079460000', '442079460000'],
    'punctuated' => ['(020) 7946-0000', '02079460000'],
    // Short enough to be a typo rather than a number.
    'too short' => ['12345', null],
]);

it('shows enough of an entry to recognise it and no more', function (): void {
    // A register of people who want nothing to do with us is not a contact book
    // (docs/compliance.md §3.9).
    expect(SuppressionType::Email->preview('dana@acme.test'))->toBe('d***@acme.test')
        // A domain is a company, not a person — shown whole, which is the point of blocking one.
        ->and(SuppressionType::Domain->preview('acme.test'))->toBe('acme.test')
        ->and(SuppressionType::Phone->preview('442079460000'))->toBe('***0000');
});

it('escapes nothing into the hash — the same identity hashes the same way every time', function (): void {
    $first = SuppressionType::Email->normalise('Dana@Acme.test');
    $second = SuppressionType::Email->normalise(' dana@acme.TEST ');

    // Deterministic and unsalted on purpose: recognising the same address arriving later from a
    // different provider is the entire function.
    expect($first)->toBe($second)
        ->and(hash('sha256', (string) $first))->toBe(hash('sha256', (string) $second));
});
