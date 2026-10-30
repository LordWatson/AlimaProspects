<?php

declare(strict_types=1);

use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Enums\SignalWatchCadence;

it('exposes stable values and labels for every event type', function (): void {
    expect(SignalEventType::values())->toBe(['acquisition', 'funding_round', 'relocation']);

    foreach (SignalEventType::cases() as $case) {
        expect($case->label())->not->toBe('')
            ->and($case->description())->not->toBe('');
    }
});

it('exposes stable values and labels for signal status', function (): void {
    expect(SignalStatus::values())->toBe(['new', 'dismissed', 'converted']);

    foreach (SignalStatus::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

it('maps cadence to a staleness window the scheduler can use', function (): void {
    expect(SignalWatchCadence::values())->toBe(['daily', 'weekly'])
        ->and(SignalWatchCadence::Daily->staleAfterHours())->toBe(24)
        ->and(SignalWatchCadence::Weekly->staleAfterHours())->toBe(168);
});

it('knows which run statuses are terminal', function (): void {
    expect(SignalRunStatus::values())->toBe(['queued', 'running', 'completed', 'failed'])
        ->and(SignalRunStatus::Queued->isActive())->toBeTrue()
        ->and(SignalRunStatus::Running->isActive())->toBeTrue()
        ->and(SignalRunStatus::Completed->isTerminal())->toBeTrue()
        ->and(SignalRunStatus::Failed->isTerminal())->toBeTrue()
        ->and(SignalRunStatus::Running->isTerminal())->toBeFalse();
});
