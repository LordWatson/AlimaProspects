<?php

declare(strict_types=1);

use App\Modules\Scoring\Data\QualitativeFit;

it('clamps the score into 0–100 whatever the model returns', function (mixed $given, int $expected): void {
    expect(QualitativeFit::fromAi(['score' => $given, 'rationale' => 'ok'])->score)->toBe($expected);
})->with([
    [5_000, 100],
    [-40, 0],
    [73, 73],
    ['82', 82],
    [null, 0],
]);

it('trims and caps the rationale and the lists', function (): void {
    $fit = QualitativeFit::fromAi([
        'score' => 50,
        'rationale' => '  '.str_repeat('word ', 1_000).'  ',
        'strengths' => ['One', 'One', '  Two  ', '', 'Three', 'Four', 'Five', 'Six', 'Seven'],
        'concerns' => 'not a list',
    ]);

    expect(mb_strlen($fit->rationale))->toBeLessThanOrEqual(1_500)
        // De-duplicated, trimmed, empties dropped, capped at six.
        ->and($fit->strengths)->toBe(['One', 'Two', 'Three', 'Four', 'Five', 'Six'])
        ->and($fit->concerns)->toBe([]);
});

it('defaults to an empty assessment when the payload is bare', function (): void {
    $fit = QualitativeFit::fromAi([]);

    expect($fit->score)->toBe(0)
        ->and($fit->rationale)->toBe('')
        ->and($fit->strengths)->toBe([])
        ->and($fit->concerns)->toBe([]);
});
