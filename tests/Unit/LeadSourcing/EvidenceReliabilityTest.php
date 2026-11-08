<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\Signals\Enums\SignalSourceKind;
use Tests\TestCase;

/*
 * The structural guard behind "a source with no entry scores the default" (PL1).
 *
 * An unknown source key is deliberately legal — that is what lets a later phase add a provider
 * without redeploying the evidence model. The risk that creates is the quiet one: a source nobody
 * gave a weight to would under-count every lead it ever touched, for ever, with nothing failing.
 * These two tests are what turns that into a failing suite the day a source is added.
 */
uses(TestCase::class);

it('gives every lead source a reliability weight', function (): void {
    /** @var array<string, int> $weights */
    $weights = (array) config('evidence.reliability');

    foreach (LeadSource::cases() as $source) {
        // A missing key here means: add a reliability weight for this source in
        // config/evidence.php. `toHaveKey` with a second argument asserts the *value*, so the
        // check is written as a plain existence test.
        expect(array_key_exists($source->value, $weights))->toBeTrue();
    }
});

it('gives every signal source a reliability weight', function (): void {
    /** @var array<string, int> $weights */
    $weights = (array) config('evidence.reliability');

    foreach (SignalSourceKind::cases() as $source) {
        expect(array_key_exists($source->value, $weights))->toBeTrue();
    }
});

it('gives every evidence kind a half-life', function (): void {
    /** @var array<string, int> $halfLives */
    $halfLives = (array) config('evidence.recency_half_life_days');

    foreach (EvidenceKind::cases() as $kind) {
        expect($halfLives)->toHaveKey($kind->value)
            ->and($kind->halfLifeDays())->toBeGreaterThan(0);
    }
});

it('keeps a fallback for a source this build does not know', function (): void {
    expect(config('evidence.reliability.default'))->toBeInt()
        ->and((int) config('evidence.reliability.default'))->toBeGreaterThan(0)
        // Under-weighted rather than over: a provider nobody has taken a view on should not out-
        // rank Companies House by accident.
        ->and((int) config('evidence.reliability.default'))
        ->toBeLessThan((int) config('evidence.reliability.companies_house'));
});
