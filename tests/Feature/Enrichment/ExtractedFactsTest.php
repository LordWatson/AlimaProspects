<?php

declare(strict_types=1);

use App\Modules\Enrichment\Data\ExtractedFacts;

it('coerces and bounds a loosely-typed AI payload', function (): void {
    $facts = ExtractedFacts::fromAi([
        'company_name' => '  Acme Ltd  ',
        'website' => 'https://acme.test',
        'industry' => 'Coffee roasting',
        'employee_range' => '11-50',
        'locations' => ['London', 'London', 'Leeds', 42],
        'technologies' => ['Shopify', ''],
        'emails' => ['INFO@acme.test', 'info@acme.test', 'sales@acme.test'],
        'phone' => '+44 20 7946 0000',
    ]);

    expect($facts->companyName)->toBe('Acme Ltd')
        ->and($facts->industry)->toBe('Coffee roasting')
        ->and($facts->employeeRange)->toBe('11-50')
        // De-duplicated, numbers coerced, blanks dropped.
        ->and($facts->locations)->toBe(['London', 'Leeds', '42'])
        ->and($facts->technologies)->toBe(['Shopify'])
        // Lower-cased and de-duplicated.
        ->and($facts->emails)->toBe(['info@acme.test', 'sales@acme.test'])
        ->and($facts->phone)->toBe('+44 20 7946 0000');
});

it('salvages a size band from a corrupted employee_range and strips markup', function (): void {
    // The real lead-14 failure: the model leaked its own JSON structure and page markup into the
    // field. Keep the leading size figure, drop the rest.
    $facts = ExtractedFacts::fromAi([
        'employee_range' => "11-50','locality':'London'}]}</br>Skip</br>",
    ]);

    expect($facts->employeeRange)->toBe('11-50');
});

it('keeps a clean employee range, numeric or plain word', function (string $input, ?string $expected): void {
    expect(ExtractedFacts::fromAi(['employee_range' => $input])->employeeRange)->toBe($expected);
})->with([
    ['11-50 employees', '11-50 employees'],
    ['500+', '500+'],
    ['1,000 to 5,000', '1,000 to 5,000'],
    ['Enterprise', 'Enterprise'],
    ['<span>51-200</span>', '51-200'],
    // Junk with no salvageable figure is dropped rather than stored.
    ["{'foo':'bar'}", null],
]);

it('strips HTML and decodes entities the extractor dragged in from the page', function (): void {
    $facts = ExtractedFacts::fromAi([
        'company_name' => '<b>Pip &amp; Nut</b>',
        'industry' => '<p>Nut butter producer</p>',
    ]);

    expect($facts->companyName)->toBe('Pip & Nut')
        ->and($facts->industry)->toBe('Nut butter producer');
});

it('defaults missing fields to null / empty', function (): void {
    $facts = ExtractedFacts::fromAi([]);

    expect($facts->companyName)->toBeNull()
        ->and($facts->website)->toBeNull()
        ->and($facts->industry)->toBeNull()
        ->and($facts->locations)->toBe([])
        ->and($facts->emails)->toBe([]);
});

it('ignores non-array list fields and non-string emails', function (): void {
    $facts = ExtractedFacts::fromAi([
        'locations' => 'not-a-list',
        'emails' => ['ok@acme.test', 123, ['nested']],
    ]);

    expect($facts->locations)->toBe([])
        ->and($facts->emails)->toBe(['ok@acme.test']);
});

it('shapes named people and drops the nameless', function (): void {
    $facts = ExtractedFacts::fromAi([
        'people' => [
            ['name' => '  Jane Smith  ', 'title' => 'Founder'],
            ['name' => 'Bob', 'title' => null],
            ['title' => 'Nameless role'], // dropped: no name
            'not-an-object',              // dropped
        ],
    ]);

    expect($facts->people)->toBe([
        ['name' => 'Jane Smith', 'title' => 'Founder'],
        ['name' => 'Bob', 'title' => null],
    ]);
});

it('defaults people to an empty list', function (): void {
    expect(ExtractedFacts::fromAi([])->people)->toBe([]);
});
