<?php

declare(strict_types=1);

use App\Modules\ICP\Enums\CriterionType;

it('separates weighted signals from the exclusion type', function (): void {
    $signals = CriterionType::signals();

    expect($signals)->not->toContain(CriterionType::Exclusion)
        ->and(CriterionType::Exclusion->isExclusion())->toBeTrue()
        ->and(CriterionType::Industry->isExclusion())->toBeFalse()
        ->and(count($signals))->toBe(count(CriterionType::cases()) - 1);
});

it('exposes a stable value list and a label + placeholder for every case', function (): void {
    expect(CriterionType::values())->toContain('industry', 'exclusion');

    foreach (CriterionType::cases() as $type) {
        expect($type->label())->not->toBe('')
            ->and($type->placeholder())->not->toBe('');
    }
});
