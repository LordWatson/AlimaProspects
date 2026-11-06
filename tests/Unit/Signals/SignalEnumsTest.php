<?php

declare(strict_types=1);

use App\Modules\Signals\Enums\SignalCategory;
use App\Modules\Signals\Enums\SignalIcon;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Enums\SignalWatchCadence;

/*
 * `SignalEventType` used to be tested here. It is gone (ADR 0018): nothing in the app branched on
 * which case it was, so the type became a row. What is left on this file is the set of enums that
 * **are** branched on - plus the two S7 added, which are enums for exactly that reason: the UI
 * renders a section per category and maps an icon key to an imported component, so both sets have
 * to stay closed.
 */

it('exposes stable values and labels for every signal category', function (): void {
    expect(SignalCategory::values())->toBe([
        'liquidity', 'growth', 'movement', 'distress', 'hiring', 'regulatory', 'custom',
    ]);

    foreach (SignalCategory::cases() as $case) {
        expect($case->label())->not->toBe('')
            ->and($case->description())->not->toBe('');
    }
});

it('exposes a closed, labelled icon allowlist', function (): void {
    // A free string here would reach the client as a component name, so the set is closed on
    // purpose (ADR 0018 section 8) and every case has to be renderable.
    expect(SignalIcon::values())->toContain('satellite')
        ->and(SignalIcon::values())->toHaveCount(count(SignalIcon::cases()));

    foreach (SignalIcon::cases() as $case) {
        expect($case->label())->not->toBe('')
            ->and($case->value)->toMatch('/^[a-z0-9-]+$/'); // a Lucide name, kebab-case
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
