<?php

declare(strict_types=1);

use App\Modules\Core\Support\TeamContext;

it('holds, scopes and restores the active team id', function (): void {
    $context = new TeamContext;

    expect($context->has())->toBeFalse()
        ->and($context->id())->toBeNull();

    $context->set(5);

    expect($context->id())->toBe(5)
        ->and($context->has())->toBeTrue();

    $result = $context->run(9, fn (): ?int => $context->id());

    expect($result)->toBe(9)
        ->and($context->id())->toBe(5); // restored after run()

    $context->forget();

    expect($context->id())->toBeNull();
});
