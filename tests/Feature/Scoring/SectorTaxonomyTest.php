<?php

declare(strict_types=1);

use App\Modules\Scoring\Support\SectorTaxonomy;

it('places vocabularies that share no words on the same sector', function (): void {
    $taxonomy = app(SectorTaxonomy::class);

    expect($taxonomy->sectorOf('SaaS'))->toBe('software')
        ->and($taxonomy->sectorOf('Cloud software development'))->toBe('software')
        ->and($taxonomy->sectorOf('SaaS'))->toBe($taxonomy->sectorOf('Cloud software development'));
});

it('keeps genuinely different sectors apart', function (): void {
    $taxonomy = app(SectorTaxonomy::class);

    expect($taxonomy->sectorOf('Third-party logistics'))->toBe('logistics')
        ->and($taxonomy->sectorOf('Freight forwarding'))->toBe('logistics')
        ->and($taxonomy->sectorOf('Speciality coffee roasting'))->toBe('food_beverage')
        ->and($taxonomy->sectorOf('Logistics'))->not->toBe($taxonomy->sectorOf('Cloud software'));
});

it('returns null for a vocabulary it does not carry', function (): void {
    $taxonomy = app(SectorTaxonomy::class);

    expect($taxonomy->sectorOf('Bespoke umbrella repair'))->toBeNull()
        ->and($taxonomy->sectorOf(''))->toBeNull()
        ->and($taxonomy->sectorOf(null))->toBeNull();
});

it('matches keywords on whole tokens, not inside other words', function (): void {
    $taxonomy = app(SectorTaxonomy::class);

    // "car" (automotive uses "car dealership", not a bare "car") must not fire on "carpentry".
    expect($taxonomy->sectorOf('Carpentry'))->not->toBe('automotive');
});
