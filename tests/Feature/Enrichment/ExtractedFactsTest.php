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
