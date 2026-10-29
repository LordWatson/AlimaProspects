<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Support\CircuitBreaker;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function breaker(int $threshold = 3): CircuitBreaker
{
    return new CircuitBreaker(new Repository(new ArrayStore), threshold: $threshold, cooldownSeconds: 60);
}

it('allows calls until the failure threshold is reached, then opens', function (): void {
    $cb = breaker(threshold: 3);

    expect($cb->allows('ch'))->toBeTrue();

    $cb->recordFailure('ch');
    $cb->recordFailure('ch');
    expect($cb->allows('ch'))->toBeTrue(); // 2 < 3

    $cb->recordFailure('ch');
    expect($cb->allows('ch'))->toBeFalse(); // 3 >= 3 → open
});

it('closes again on a success', function (): void {
    $cb = breaker(threshold: 2);

    $cb->recordFailure('ch');
    $cb->recordFailure('ch');
    expect($cb->allows('ch'))->toBeFalse();

    $cb->recordSuccess('ch');
    expect($cb->allows('ch'))->toBeTrue();
});

it('tracks keys independently', function (): void {
    $cb = breaker(threshold: 1);

    $cb->recordFailure('ch');
    expect($cb->allows('ch'))->toBeFalse()
        ->and($cb->allows('other'))->toBeTrue();
});
