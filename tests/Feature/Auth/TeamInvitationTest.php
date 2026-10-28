<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Actions\InviteTeamMemberAction;
use App\Modules\Auth\Data\InviteMemberData;
use App\Modules\Auth\Mail\TeamInvitationMail;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function freshInvitation(User $owner, string $email = 'joiner@example.com', TeamRole $role = TeamRole::Member): TeamInvitation
{
    $team = $owner->currentTeam()->firstOrFail();

    return app(InviteTeamMemberAction::class)->handle(
        $team,
        new InviteMemberData($email, $role),
        $owner,
    );
}

it('creates a pending invitation when an owner invites someone', function (): void {
    Mail::fake();

    $owner = createUserWithTeam();

    $this->actingAs($owner)
        ->post(route('team.invitations.store'), ['email' => 'new@example.com', 'role' => 'member'])
        ->assertRedirect();

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $owner->currentTeamId(),
        'email' => 'new@example.com',
        'role' => 'member',
        'accepted_at' => null,
    ]);
});

it('sends the invitation email', function (): void {
    Mail::fake();

    $owner = createUserWithTeam();
    freshInvitation($owner, 'mailme@example.com');

    Mail::assertQueued(TeamInvitationMail::class, fn (TeamInvitationMail $mail): bool => $mail->hasTo('mailme@example.com'));
});

it('lets the invited user accept and join the team with the invited role', function (): void {
    Mail::fake();

    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    $invitation = freshInvitation($owner, 'joiner@example.com', TeamRole::Admin);

    $joiner = User::factory()->create(['email' => 'joiner@example.com']);

    $this->actingAs($joiner)
        ->post(route('invitations.accept', ['token' => $invitation->token]))
        ->assertRedirect(route('dashboard'));

    $joiner->refresh();

    expect($joiner->belongsToTeam($team))->toBeTrue()
        ->and($joiner->roleOn($team))->toBe(TeamRole::Admin)
        ->and($joiner->currentTeamId())->toBe((int) $team->getKey())
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('refuses acceptance by a user whose email does not match', function (): void {
    Mail::fake();

    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    $invitation = freshInvitation($owner, 'intended@example.com');

    $wrong = User::factory()->create(['email' => 'someone-else@example.com']);

    $this->actingAs($wrong)
        ->post(route('invitations.accept', ['token' => $invitation->token]));

    expect($wrong->fresh()->belongsToTeam($team))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

it('refuses acceptance of an expired invitation', function (): void {
    Mail::fake();

    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    $invitation = freshInvitation($owner, 'late@example.com');
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    $joiner = User::factory()->create(['email' => 'late@example.com']);

    $this->actingAs($joiner)
        ->post(route('invitations.accept', ['token' => $invitation->token]));

    expect($joiner->fresh()->belongsToTeam($team))->toBeFalse();
});

it('revokes a pending invitation', function (): void {
    Mail::fake();

    $owner = createUserWithTeam();
    $invitation = freshInvitation($owner, 'revoke@example.com');

    $this->actingAs($owner)
        ->delete(route('team.invitations.destroy', $invitation))
        ->assertRedirect();

    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
});

it('resends a pending invitation and refreshes its expiry', function (): void {
    Mail::fake();

    $owner = createUserWithTeam();
    $invitation = freshInvitation($owner, 'again@example.com');
    $invitation->forceFill(['expires_at' => now()->addHour()])->save();
    $originalExpiry = $invitation->fresh()->expires_at;

    $this->actingAs($owner)
        ->post(route('team.invitations.resend', $invitation))
        ->assertRedirect();

    expect($invitation->fresh()->expires_at->gt($originalExpiry))->toBeTrue();
});

it('forbids a non-manager from inviting members', function (): void {
    Mail::fake();

    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();

    $member = User::factory()->create();
    app(TeamMembership::class)->add($member, $team, TeamRole::Member);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($member->refresh())
        ->post(route('team.invitations.store'), ['email' => 'x@example.com', 'role' => 'member'])
        ->assertForbidden();
});
