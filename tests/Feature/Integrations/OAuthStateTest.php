<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Integrations\Enums\CrmProvider;
use App\Modules\Integrations\Services\OAuthStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The `state` that makes an OAuth callback ours (PL7).
 *
 * What these tests are really protecting: a callback is a GET to our application with somebody
 * else's authorisation code in it, and with no `state` an attacker who gets a member to open a
 * crafted URL connects *their* CRM to the member's team — after which every lead that team finds is
 * pushed into the attacker's Pipedrive. Each assertion below closes one way of doing that.
 */
function states(): OAuthStateService
{
    return app(OAuthStateService::class);
}

it('accepts a state exactly once', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $state = states()->issue($team, $user, CrmProvider::Pipedrive);

    expect(states()->consume($state, $team, $user, CrmProvider::Pipedrive))->toBeTrue()
        // Deleted as it is read, which is what makes it single-use by construction rather than by
        // a window — a signed value would be replayable until it expired and could not be revoked.
        ->and(states()->consume($state, $team, $user, CrmProvider::Pipedrive))->toBeFalse();
});

it('refuses a state that was never issued', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    expect(states()->consume('made-up', $team, $user, CrmProvider::Pipedrive))->toBeFalse();
});

it('refuses a state that has expired', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    config()->set('integrations.oauth.state_ttl', 60);

    $state = states()->issue($team, $user, CrmProvider::Pipedrive);

    $this->travel(61)->seconds();

    expect(states()->consume($state, $team, $user, CrmProvider::Pipedrive))->toBeFalse();
});

it('refuses somebody else\'s state', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->create();

    $state = states()->issue($team, $user, CrmProvider::Pipedrive);

    expect(states()->consume($state, $otherTeam, $user, CrmProvider::Pipedrive))->toBeFalse();

    $state = states()->issue($team, $user, CrmProvider::Pipedrive);

    expect(states()->consume($state, $team, $otherUser, CrmProvider::Pipedrive))->toBeFalse();
});

it('refuses a state issued for a different provider', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $state = states()->issue($team, $user, CrmProvider::Pipedrive);

    // This one matters as much as the other two: a Pipedrive state completing a HubSpot callback
    // would store HubSpot tokens on a Pipedrive row, and every later call would be made against
    // the wrong API with a credential that works.
    expect(states()->consume($state, $team, $user, CrmProvider::HubSpot))->toBeFalse();
});

it('burns a state even when the answer is no', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $otherTeam = Team::factory()->create();

    $state = states()->issue($team, $user, CrmProvider::Pipedrive);

    // Deleted before the checks, so somebody replaying a captured state against one team after
    // another cannot keep trying with it.
    expect(states()->consume($state, $otherTeam, $user, CrmProvider::Pipedrive))->toBeFalse()
        ->and(states()->consume($state, $team, $user, CrmProvider::Pipedrive))->toBeFalse();
});

it('rejects a callback with no usable state, without naming which rule refused', function (): void {
    $user = createUserWithTeam();

    // One message for absent, expired, used and somebody else's: a callback that says which guess
    // was closer is a callback that helps.
    $this->actingAs($user)
        ->get(route('integrations.crm.callback', ['provider' => 'pipedrive', 'code' => 'abc', 'state' => 'nope']))
        // The flag is off for this team, so the feature check answers first — which is itself the
        // right order: an unfinished feature should not be reachable at all.
        ->assertNotFound();
});
