<?php

declare(strict_types=1);

use App\Modules\BusinessIntelligence\Data\BackgroundCheckFindings;

it('hydrates findings from the AI payload and defaults missing lists', function (): void {
    $findings = BackgroundCheckFindings::fromAi([
        'summary' => 'A summary.',
        'offering' => 'An offering.',
        'positioning' => 'A position.',
        'own_industry' => 'Graphic design',
        'customer_industries' => ['Hospitality', 'Retail'],
        'decision_maker_titles' => ['Marketing Director'],
        'confidence' => 0.75,
    ]);

    expect($findings->summary)->toBe('A summary.')
        ->and($findings->ownIndustry)->toBe('Graphic design')
        ->and($findings->customerIndustries)->toBe(['Hospitality', 'Retail'])
        ->and($findings->decisionMakerTitles)->toBe(['Marketing Director'])
        ->and($findings->targetCustomers)->toBe([])
        ->and($findings->competitors)->toBe([])
        ->and($findings->confidence)->toBe(0.75)
        ->and($findings->hasCustomerSignals())->toBeTrue();
});

it('reads a v1 payload’s industries as the team’s own, never as its customers’', function (): void {
    // Prompt v1 had one `suggested_industries` list and no statement of what it meant; its framing
    // only supported one reading — the industry the business is IN. Reusing those values as customer
    // industries is what sent a design studio looking for design studios, so they are read as the
    // team's own trade and the customer side is left empty on purpose.
    $findings = BackgroundCheckFindings::fromAi([
        'summary' => 'A summary.',
        'offering' => 'An offering.',
        'positioning' => 'A position.',
        'suggested_industries' => ['Graphic design', 'Branding'],
    ]);

    expect($findings->ownIndustry)->toBe('Graphic design')
        ->and($findings->customerIndustries)->toBe([])
        ->and($findings->hasCustomerSignals())->toBeFalse();
});

it('reports no customer signals when the model could not tell who buys', function (): void {
    $findings = BackgroundCheckFindings::fromAi([
        'summary' => 's', 'offering' => 'o', 'positioning' => 'p',
        'own_industry' => 'Graphic design',
    ]);

    expect($findings->hasCustomerSignals())->toBeFalse();
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
        'own_industry', 'customer_industries', 'target_customers',
        'decision_maker_titles', 'competitors', 'confidence',
    ])->and($storage['confidence'])->toBeNull()
        // The conflated v1 key is gone from what we write: one list meaning both sides is the bug.
        ->and($storage)->not->toHaveKey('suggested_industries');
});
