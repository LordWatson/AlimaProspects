<?php

declare(strict_types=1);

use App\Modules\BusinessIntelligence\Data\BackgroundCheckFindings;

it('hydrates findings from the AI payload and defaults missing lists', function (): void {
    $findings = BackgroundCheckFindings::fromAi([
        'summary' => 'A summary.',
        'offering' => 'An offering.',
        'positioning' => 'A position.',
        'suggested_industries' => ['SaaS', 'Logistics'],
        'confidence' => 0.75,
    ]);

    expect($findings->summary)->toBe('A summary.')
        ->and($findings->suggestedIndustries)->toBe(['SaaS', 'Logistics'])
        ->and($findings->targetCustomers)->toBe([])
        ->and($findings->competitors)->toBe([])
        ->and($findings->confidence)->toBe(0.75);
});

it('coerces and cleans loosely-typed list values', function (): void {
    $findings = BackgroundCheckFindings::fromAi([
        'summary' => 'x',
        'offering' => 'y',
        'positioning' => 'z',
        'target_customers' => ['  Founder  ', '', ['nested'], 42],
    ]);

    // Blanks and non-scalars dropped; strings trimmed; ints stringified.
    expect($findings->targetCustomers)->toBe(['Founder', '42']);
});

it('round-trips to storage with snake_case keys', function (): void {
    $storage = BackgroundCheckFindings::fromAi([
        'summary' => 's',
        'offering' => 'o',
        'positioning' => 'p',
    ])->toStorage();

    expect($storage)->toHaveKeys([
        'summary', 'offering', 'positioning',
        'target_customers', 'competitors', 'suggested_industries', 'confidence',
    ])->and($storage['confidence'])->toBeNull();
});
