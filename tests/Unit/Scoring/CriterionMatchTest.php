<?php

declare(strict_types=1);

use App\Modules\Scoring\Enums\CriterionMatch;
use App\Modules\Scoring\Enums\FeedbackRating;

it('counts only matched and not-matched towards the weighted ratio', function (): void {
    expect(CriterionMatch::Matched->isAssessed())->toBeTrue()
        ->and(CriterionMatch::NotMatched->isAssessed())->toBeTrue()
        // Missing data must not be read as a failure.
        ->and(CriterionMatch::Unknown->isAssessed())->toBeFalse();
});

it('exposes a stable value list and a label for every case', function (): void {
    expect(CriterionMatch::values())->toBe(['matched', 'not_matched', 'unknown'])
        ->and(FeedbackRating::values())->toBe(['good', 'bad']);

    foreach ([...CriterionMatch::cases(), ...FeedbackRating::cases()] as $case) {
        expect($case->label())->not->toBe('');
    }
});
