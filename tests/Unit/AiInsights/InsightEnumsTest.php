<?php

declare(strict_types=1);

use App\Modules\AiInsights\Enums\InsightSeverity;
use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Enums\InsightType;

it('gives every type a label, a description and a default severity', function (InsightType $type): void {
    expect($type->label())->not->toBe('')
        ->and($type->description())->not->toBe('')
        ->and($type->defaultSeverity())->toBeInstanceOf(InsightSeverity::class);
})->with(InsightType::cases());

it('marks only the digest as written by the model', function (): void {
    $written = array_values(array_filter(
        InsightType::cases(),
        static fn (InsightType $type): bool => $type->isWrittenByAi(),
    ));

    // The split the whole module rests on: everything else is SQL, costs nothing and is worded on
    // the client in the member's own language.
    expect($written)->toBe([InsightType::PeriodicDigest]);
});

it('orders severity by how much it asks of the reader', function (): void {
    $weights = array_map(
        static fn (InsightSeverity $severity): int => $severity->weight(),
        InsightSeverity::cases(),
    );

    expect(InsightSeverity::Action->weight())->toBeGreaterThan(InsightSeverity::Warning->weight())
        ->and(InsightSeverity::Warning->weight())->toBeGreaterThan(InsightSeverity::Info->weight())
        // Distinct, or the feed's ordering would be arbitrary between two levels.
        ->and(array_unique($weights))->toHaveCount(count($weights));
});

it('has no status nothing can reach', function (): void {
    // `applied` is planned for the one-click ICP tweak and deliberately absent until something can
    // produce it (see the enum's docblock).
    expect(InsightStatus::values())->toBe(['new', 'dismissed']);
});

it('keeps every enum value inside its column', function (): void {
    // `insights.type`, `.status` and `.severity` are varchar(32)/(16)/(16).
    foreach (InsightType::values() as $value) {
        expect(strlen($value))->toBeLessThanOrEqual(32);
    }

    foreach ([...InsightStatus::values(), ...InsightSeverity::values()] as $value) {
        expect(strlen($value))->toBeLessThanOrEqual(16);
    }
});
