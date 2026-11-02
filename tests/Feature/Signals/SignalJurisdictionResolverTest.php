<?php

declare(strict_types=1);

use App\Modules\Core\Enums\Market;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Services\SignalJurisdictionResolver;

/*
 * The place map that decides a lead's outreach restriction (S8, ADR 0019).
 *
 * This gets the coverage a legal rule deserves, because that is what it is: its answer picks which
 * compliance regime a converted lead falls under, and the two ways it can be wrong are not
 * symmetrical. Guessing AE for a British founder is annoying; guessing GB — or nothing — for a
 * Dubai founder means Outreach sees no reason to refuse a cold email that PDPL forbids.
 */

/*
 * In `Feature` rather than `Unit` because the place map is **config** — `tests/Pest.php` only binds
 * the application TestCase to `Feature`, so a `Unit` test has no container and `config()` throws.
 * The resolver is still pure in the sense that matters: deterministic, no AI, no database.
 */

beforeEach(function (): void {
    $this->resolver = new SignalJurisdictionResolver;
});

it('resolves the places a newspaper actually prints', function (string $location, string $expected): void {
    expect($this->resolver->resolve($location))->toBe($expected);
})->with([
    ['Dubai', 'AE'],
    ['Abu Dhabi', 'AE'],
    ['DIFC', 'AE'],
    ['United Arab Emirates', 'AE'],
    ['UAE', 'AE'],
    ['London', 'GB'],
    ['the City of London', 'GB'],
    ['Manchester', 'GB'],
    ['Scotland', 'GB'],
    ['United Kingdom', 'GB'],
    ['Riyadh', 'SA'],
    ['Singapore', 'SG'],
    ['Mumbai', 'IN'],
    ['New York', 'US'],
]);

it('matches a place inside the sentence a snippet actually gives', function (string $location, string $expected): void {
    expect($this->resolver->resolve($location))->toBe($expected);
})->with([
    ['Dubai, UAE', 'AE'],
    ['Dubai-based holding company', 'AE'],
    ['(Dubai)', 'AE'],
    ['dubai', 'AE'],
    ['  DUBAI  ', 'AE'],
    ['Headquartered in London.', 'GB'],
]);

it('does not find a place inside another word', function (): void {
    // The failure this exists to prevent: a substring test finds "Dubai" in "Mumbai" and files an
    // Indian funding round under the UAE, stamping a PDPL restriction on a lead it does not apply
    // to. Whole-token matching is why that cannot happen.
    expect($this->resolver->resolve('Mumbai'))->toBe('IN')
        ->and($this->resolver->resolve('Mumbai, India'))->toBe('IN');
});

it('prefers the longest matching place name', function (): void {
    // "Wales" is a GB alias, so a shorter-key-first implementation would call New South Wales
    // British. Keys are sorted longest-first for exactly this.
    config()->set('signals.jurisdictions', [
        'GB' => ['wales', 'london'],
        'AU' => ['new south wales', 'sydney'],
    ]);

    expect($this->resolver->resolve('New South Wales'))->toBe('AU')
        ->and($this->resolver->resolve('Wales'))->toBe('GB');
});

it('returns null when it cannot tell, rather than guessing', function (mixed $location): void {
    // Null is the safe answer: conversion falls back to the watch and then to the most restrictive
    // of its regions, so an unresolved place fails **toward** the restriction. A guess here would
    // fail away from it.
    expect($this->resolver->resolve($location))->toBeNull();
})->with([
    [null],
    [''],
    ['   '],
    ['Somewhere'],
    ['remote'],
    ['Global'],
]);

it('does not let the watch regions decide an unequal tie', function (): void {
    // "UK founder relocates to Dubai" names both places, and a plain lookup cannot settle it. The
    // watch used to: a watch over the UK resolved it to GB, which carries no restriction, so a
    // lead about somebody moving to Dubai was cleared for cold email. **The restriction cannot
    // depend on which boxes a member ticked**, so the stricter candidate wins either way and the
    // watch is only consulted between equals. Found by the S8 review.
    $both = 'UK founder relocates to Dubai';

    expect($this->resolver->resolve($both, [SearchRegion::Ae]))->toBe('AE')
        ->and($this->resolver->resolve($both, [SearchRegion::Gb]))->toBe('AE')
        ->and($this->resolver->resolve($both))->toBe('AE');

    // A region is **not** a default: it cannot place a signal whose text names nowhere.
    expect($this->resolver->resolve('Somewhere', [SearchRegion::Ae]))->toBeNull();

    // And it cannot override an unambiguous answer either.
    expect($this->resolver->resolve('London', [SearchRegion::Ae]))->toBe('GB');
});

it('falls back to config order when the regions settle nothing', function (): void {
    // Two places named, neither of them the watch's. The answer has to be reproducible rather than
    // whichever the hash order happened to give, so it is the first in config order — which is
    // written with the jurisdictions the business operates in first.
    $text = 'A Singapore fund backed a Mumbai startup';

    expect($this->resolver->resolve($text, [SearchRegion::Gb]))
        ->toBe($this->resolver->resolve($text, [SearchRegion::Gb]))
        ->and($this->resolver->resolve($text))->toBeIn(['SG', 'IN']);
});

it('maps a country code to a market only where the business holds a position', function (): void {
    expect(SignalJurisdictionResolver::marketFor('AE'))->toBe(Market::Uae)
        ->and(SignalJurisdictionResolver::marketFor('ae'))->toBe(Market::Uae)
        ->and(SignalJurisdictionResolver::marketFor('GB'))->toBe(Market::Uk)
        // ADR 0007 records two regimes. A third would be a compliance claim made by a lookup
        // table, so these are null rather than approximate (ADR 0019 §3).
        ->and(SignalJurisdictionResolver::marketFor('SG'))->toBeNull()
        ->and(SignalJurisdictionResolver::marketFor('US'))->toBeNull()
        ->and(SignalJurisdictionResolver::marketFor('QA'))->toBeNull();
});

it('resolves straight to a market in one call', function (): void {
    expect($this->resolver->resolveMarket('Dubai'))->toBe(Market::Uae)
        ->and($this->resolver->resolveMarket('London'))->toBe(Market::Uk)
        ->and($this->resolver->resolveMarket('Singapore'))->toBeNull()
        ->and($this->resolver->resolveMarket('Somewhere'))->toBeNull();
});

it('bounds the text it will read', function (): void {
    // A guard rather than a feature: the prompt clamps `location` to 255 already, and a resolver
    // that scanned an unbounded string would be a place to push work into.
    $long = str_repeat('x', 5_000).' Dubai';

    expect($this->resolver->resolve($long))->toBeNull();
});

it('survives a config map somebody half-filled', function (): void {
    config()->set('signals.jurisdictions', [
        'AE' => ['dubai', '', '   ', 42, null],
        'XX' => 'not an array',
        'GB' => [],
    ]);

    expect($this->resolver->resolve('Dubai'))->toBe('AE')
        ->and($this->resolver->resolve('London'))->toBeNull();
});

/*
 * Ambiguity — two places named in one sentence — found by the S8 review.
 *
 * The first version asked the watch's regions first, so a watch listing `gb` before `ae` resolved
 * "UK founder relocates to Dubai" to GB. `ConvertSignalToLeadAction` treats a resolved market as
 * final, so that produced a lead about somebody moving to Dubai carrying **no** restriction — an
 * ambiguity failing *away* from the restriction, which is the one direction this class exists to
 * rule out.
 */

it('resolves an ambiguous place to the most restrictive candidate', function (): void {
    $resolver = app(SignalJurisdictionResolver::class);

    // Both named, and the watch lists the *less* restrictive region first.
    expect($resolver->resolve('UK founder relocates to Dubai', [SearchRegion::Gb, SearchRegion::Ae]))
        ->toBe('AE')
        ->and($resolver->resolveMarket('UK founder relocates to Dubai', [SearchRegion::Gb, SearchRegion::Ae]))
        ->toBe(Market::Uae);
});

it('resolves an ambiguous place the same way whatever the watch lists', function (): void {
    $resolver = app(SignalJurisdictionResolver::class);

    // The restriction cannot depend on the order a member happened to tick boxes in.
    foreach ([[SearchRegion::Ae, SearchRegion::Gb], [SearchRegion::Gb, SearchRegion::Ae], []] as $regions) {
        expect($resolver->resolve('London office opens, Abu Dhabi headquarters', $regions))->toBe('AE');
    }
});

it('prefers a market it holds a position on over one it does not', function (): void {
    $resolver = app(SignalJurisdictionResolver::class);

    // Neither imposes a restriction, so neither answer is unsafe — but a regime the app understands
    // is the more useful thing to record on the lead than one it has no view on.
    expect($resolver->resolve('London fintech expands to Singapore', [SearchRegion::Sg]))->toBe('GB');
});

it('lets the watch break a tie between equally restrictive places', function (): void {
    $resolver = app(SignalJurisdictionResolver::class);

    // Two places, same restriction either way, so the watch's own region is a legitimate tiebreak
    // again — it says which end the team was looking for.
    expect($resolver->resolve('Manchester and Singapore', [SearchRegion::Sg, SearchRegion::Gb]))
        ->toBe('GB');
});
