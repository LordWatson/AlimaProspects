<?php

declare(strict_types=1);

use App\Modules\Scoring\Support\SeniorityLadder;

it('ranks a title on the ladder, most-senior keyword winning', function (): void {
    $ladder = app(SeniorityLadder::class);

    expect($ladder->rankOf('Chief Executive Officer'))->toBe(5)
        ->and($ladder->rankOf('Founder'))->toBe(5)
        // "Managing Director" is a 5 and must not be caught by the "director" keyword a rung down.
        ->and($ladder->rankOf('Managing Director'))->toBe(5)
        ->and($ladder->rankOf('VP of Sales'))->toBe(4)
        ->and($ladder->rankOf('Head of Growth'))->toBe(3)
        ->and($ladder->rankOf('Director of Operations'))->toBe(3)
        ->and($ladder->rankOf('Marketing Manager'))->toBe(2)
        ->and($ladder->rankOf('Data Analyst'))->toBe(1);
});

it('returns null for a title it cannot place', function (): void {
    $ladder = app(SeniorityLadder::class);

    expect($ladder->rankOf('Growth Ninja'))->toBeNull()
        ->and($ladder->rankOf(''))->toBeNull()
        ->and($ladder->rankOf(null))->toBeNull();
});

it('reads the floor a seniority criterion names', function (): void {
    $ladder = app(SeniorityLadder::class);

    expect($ladder->floorOf('Director or above'))->toBe(3)
        ->and($ladder->floorOf('C-level'))->toBe(5)
        ->and($ladder->floorOf('C-suite only'))->toBe(5)
        ->and($ladder->floorOf('VP or above'))->toBe(4)
        ->and($ladder->floorOf('Senior'))->toBe(2)
        // Names no recognisable level → the scorer falls back to a literal compare.
        ->and($ladder->floorOf('Decision maker'))->toBeNull();
});
