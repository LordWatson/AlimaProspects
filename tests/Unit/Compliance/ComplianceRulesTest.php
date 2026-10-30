<?php

declare(strict_types=1);

use App\Modules\Compliance\Enums\LiaStatus;
use App\Modules\Compliance\Enums\RetentionAction;
use App\Modules\Compliance\Enums\SubjectDataCategory;
use App\Modules\Outreach\Enums\SuppressionReason;

/**
 * The rules this module carries in its own enums — the judgements that hold with no database and no
 * request behind them.
 *
 * The model's own in-force rule needs a booted application (Eloquent reads config), so it lives in
 * `tests/Feature/Compliance/LiaGateTest.php` beside the gate that asks it.
 */
it('refuses to lift the suppression reasons that are somebody’s own instruction', function (): void {
    // Scope answers first — nobody lifts a global entry — and the reason answers second, because a
    // team-scoped erasure safeguard must be unliftable too (Phase 15).
    expect(SuppressionReason::OptOut->isLiftable())->toBeFalse()
        ->and(SuppressionReason::Complaint->isLiftable())->toBeFalse()
        ->and(SuppressionReason::Erasure->isLiftable())->toBeFalse()
        ->and(SuppressionReason::Bounce->isLiftable())->toBeTrue()
        ->and(SuppressionReason::Manual->isLiftable())->toBeTrue();
});

it('never calls a do-not-contact entry erasable', function (): void {
    // A subject asking for it gone is asking for something that works against them: it holds a
    // hash rather than their address, and removing it would let the next search reach them again.
    expect(SubjectDataCategory::Suppression->isErasable())->toBeFalse();

    foreach (SubjectDataCategory::cases() as $category) {
        if ($category !== SubjectDataCategory::Suppression) {
            expect($category->isErasable())->toBeTrue();
        }
    }
});

it('gives every enum case a label, so nothing renders as a storage key', function (): void {
    foreach (LiaStatus::cases() as $status) {
        expect($status->label())->not->toBe('')
            ->and($status->guidance())->not->toBe('');
    }

    foreach (RetentionAction::cases() as $action) {
        expect($action->label())->not->toBe('');
    }

    foreach (SubjectDataCategory::cases() as $category) {
        expect($category->label())->not->toBe('');
    }
});

it('keeps every enum’s stored values stable and unique', function (): void {
    foreach ([LiaStatus::values(), RetentionAction::values(), SubjectDataCategory::values()] as $values) {
        expect($values)->toBe(array_values(array_unique($values)))
            ->and($values)->not->toBeEmpty();
    }
});
