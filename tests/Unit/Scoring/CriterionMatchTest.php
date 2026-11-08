<?php

declare(strict_types=1);

use App\Modules\Scoring\Enums\CriterionMatch;
use App\Modules\Scoring\Enums\FeedbackRating;

it('counts only matched and not-matched towards the weighted ratio', function (): void {
    expect(CriterionMatch::Matched->isAssessed())->toBeTrue()
        ->and(CriterionMatch::NotMatched->isAssessed())->toBeTrue()
        // Missing data must not be read as a failure.
        ->and(CriterionMatch::Unknown->isAssessed())->toBeFalse()
        // Deferred data the rules couldn't settle abstains too — the AI half judges it.
        ->and(CriterionMatch::Weak->isAssessed())->toBeFalse();
});

it('marks only the weak outcome as deferred to the AI half', function (): void {
    expect(CriterionMatch::Weak->isDeferred())->toBeTrue()
        ->and(CriterionMatch::Unknown->isDeferred())->toBeFalse()
        ->and(CriterionMatch::Matched->isDeferred())->toBeFalse()
        ->and(CriterionMatch::NotMatched->isDeferred())->toBeFalse();
});

it('exposes a stable value list and a label for every case', function (): void {
    expect(CriterionMatch::values())->toBe(['matched', 'not_matched', 'weak', 'unknown'])
        ->and(FeedbackRating::values())->toBe(['good', 'bad']);

    foreach ([...CriterionMatch::cases(), ...FeedbackRating::cases()] as $case) {
        expect($case->label())->not->toBe('');
    }
});
