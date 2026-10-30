<?php

declare(strict_types=1);

use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{watch: SignalWatch, signal: Signal}
 */
function ownerSignalFixtures(Team $team): array
{
    return app(TeamContext::class)->run($team, function () use ($team): array {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);
        $signal = Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);

        return ['watch' => $watch, 'signal' => $signal];
    });
}

it('lets an owner view and action signals and watches', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    ['watch' => $watch, 'signal' => $signal] = ownerSignalFixtures($team);

    expect($owner->can('view', $signal))->toBeTrue()
        ->and($owner->can('convert', $signal))->toBeTrue()
        ->and($owner->can('dismiss', $signal))->toBeTrue()
        ->and($owner->can('restore', $signal))->toBeTrue()
        ->and($owner->can('view', $watch))->toBeTrue()
        ->and($owner->can('update', $watch))->toBeTrue()
        ->and($owner->can('run', $watch))->toBeTrue();
});

it('lets a read-only member view but not action', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    ['watch' => $watch, 'signal' => $signal] = ownerSignalFixtures($team);

    $readOnly = createUserWithTeam();
    app(TeamMembership::class)->add($readOnly, $team, TeamRole::ReadOnly);

    expect($readOnly->can('view', $signal))->toBeTrue()
        ->and($readOnly->can('view', $watch))->toBeTrue()
        ->and($readOnly->can('convert', $signal))->toBeFalse()
        ->and($readOnly->can('dismiss', $signal))->toBeFalse()
        ->and($readOnly->can('restore', $signal))->toBeFalse()
        ->and($readOnly->can('update', $watch))->toBeFalse()
        ->and($readOnly->can('delete', $watch))->toBeFalse()
        ->and($readOnly->can('run', $watch))->toBeFalse();
});

it('lets a plain member action signals and watches', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    ['watch' => $watch, 'signal' => $signal] = ownerSignalFixtures($team);

    $member = createUserWithTeam();
    app(TeamMembership::class)->add($member, $team, TeamRole::Member);

    expect($member->can('convert', $signal))->toBeTrue()
        ->and($member->can('dismiss', $signal))->toBeTrue()
        ->and($member->can('run', $watch))->toBeTrue();
});

it('denies everything to a user from another team', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    ['watch' => $watch, 'signal' => $signal] = ownerSignalFixtures($team);

    $outsider = createUserWithTeam();

    expect($outsider->can('view', $signal))->toBeFalse()
        ->and($outsider->can('convert', $signal))->toBeFalse()
        ->and($outsider->can('dismiss', $signal))->toBeFalse()
        ->and($outsider->can('restore', $signal))->toBeFalse()
        ->and($outsider->can('view', $watch))->toBeFalse()
        ->and($outsider->can('update', $watch))->toBeFalse()
        ->and($outsider->can('delete', $watch))->toBeFalse()
        ->and($outsider->can('run', $watch))->toBeFalse();
});
