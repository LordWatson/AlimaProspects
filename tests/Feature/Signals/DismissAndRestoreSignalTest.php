<?php

declare(strict_types=1);

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\DismissSignalAction;
use App\Modules\Signals\Actions\RestoreSignalAction;
use App\Modules\Signals\Enums\SignalDismissReason;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function dismissableSignal(Team $team, array $attributes = []): Signal
{
    return app(TeamContext::class)->run($team, function () use ($team, $attributes): Signal {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            ...$attributes,
        ]);
    });
}

it('dismisses a signal with a reason and audits it', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = dismissableSignal($team);

    $dismissed = app(TeamContext::class)->run($team, fn (): Signal => app(DismissSignalAction::class)
        ->handle($signal, $actor, SignalDismissReason::NotAnEvent));

    expect($dismissed->status)->toBe(SignalStatus::Dismissed)
        ->and($dismissed->dismiss_reason)->toBe(SignalDismissReason::NotAnEvent)
        ->and($dismissed->actioned_by)->toBe($actor->id)
        ->and($dismissed->actioned_at)->not->toBeNull();

    $audit = AuditLog::withoutGlobalScopes()->where('action', 'signals.dismissed')->sole();

    expect($audit->properties['reason'])->toBe(SignalDismissReason::NotAnEvent->value)
        // What the team decided, not a second copy of the article.
        ->and($audit->properties)->not->toHaveKey('headline');
});

it('dismisses a signal without a reason', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = dismissableSignal($team);

    $dismissed = app(TeamContext::class)->run($team, fn (): Signal => app(DismissSignalAction::class)
        ->handle($signal, $actor));

    // A member who just wants the card gone shouldn't have to justify it.
    expect($dismissed->status)->toBe(SignalStatus::Dismissed)
        ->and($dismissed->dismiss_reason)->toBeNull();
});

it('updates the reason when a dismissed signal is dismissed again', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = dismissableSignal($team, ['status' => SignalStatus::Dismissed, 'dismiss_reason' => SignalDismissReason::NotRelevant]);

    $dismissed = app(TeamContext::class)->run($team, fn (): Signal => app(DismissSignalAction::class)
        ->handle($signal, $actor, SignalDismissReason::AlreadyKnown));

    expect($dismissed->status)->toBe(SignalStatus::Dismissed)
        ->and($dismissed->dismiss_reason)->toBe(SignalDismissReason::AlreadyKnown);
});

it('restores a dismissed signal to new and clears the reason', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = dismissableSignal($team);

    app(TeamContext::class)->run($team, fn (): Signal => app(DismissSignalAction::class)
        ->handle($signal, $actor, SignalDismissReason::NotRelevant));

    $restored = app(TeamContext::class)->run($team, fn (): Signal => app(RestoreSignalAction::class)
        ->handle($signal->refresh(), $actor));

    expect($restored->status)->toBe(SignalStatus::New)
        ->and($restored->dismiss_reason)->toBeNull()
        // A `New` row carrying someone's name reads as though they did something to it.
        ->and($restored->actioned_by)->toBeNull()
        ->and($restored->actioned_at)->toBeNull();

    expect(AuditLog::withoutGlobalScopes()->where('action', 'signals.restored')->count())->toBe(1);
});

it('restores a signal that is already new without complaint', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = dismissableSignal($team);

    $restored = app(TeamContext::class)->run($team, fn (): Signal => app(RestoreSignalAction::class)
        ->handle($signal, $actor));

    expect($restored->status)->toBe(SignalStatus::New)
        // A no-op is a no-op: nothing happened, so nothing is audited.
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'signals.restored')->count())->toBe(0);
});

it('refuses to dismiss or restore a signal that has become a lead', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = dismissableSignal($team, ['status' => SignalStatus::Converted]);

    // The lead is real and sitting in someone's pipeline; "restoring" the card would invite a
    // second conversion of the same event, and dismissing it would hide the lead's own origin.
    expect(fn (): Signal => app(TeamContext::class)->run($team, fn (): Signal => app(DismissSignalAction::class)
        ->handle($signal, $actor)))->toThrow(SignalsException::class);

    expect(fn (): Signal => app(TeamContext::class)->run($team, fn (): Signal => app(RestoreSignalAction::class)
        ->handle($signal, $actor)))->toThrow(SignalsException::class);

    expect($signal->refresh()->status)->toBe(SignalStatus::Converted);
});

it('has a label for every dismiss reason and flags only the classifier miss', function (): void {
    expect(SignalDismissReason::values())->toBe(['not_relevant', 'already_known', 'not_an_event'])
        ->and(SignalDismissReason::NotAnEvent->indicatesMisclassification())->toBeTrue()
        ->and(SignalDismissReason::NotRelevant->indicatesMisclassification())->toBeFalse()
        ->and(SignalDismissReason::AlreadyKnown->indicatesMisclassification())->toBeFalse();

    foreach (SignalDismissReason::cases() as $reason) {
        expect($reason->label())->not->toBe('');
    }
});
