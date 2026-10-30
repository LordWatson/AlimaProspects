<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Actions\AcceptInvitationAction;
use App\Modules\Auth\Actions\InviteTeamMemberAction;
use App\Modules\Auth\Contracts\SeatLimitSource;
use App\Modules\Auth\Data\InviteMemberData;
use App\Modules\Auth\Exceptions\InvitationException;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Auth\Services\SeatCounter;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Billing\Services\PlanSeatLimit;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BillingFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Seats: the third seam Billing fills in (Phase 16).
 *
 * Tested through `InviteTeamMemberAction` and `AcceptInvitationAction` rather than through
 * `PlanSeatLimit`, for the same reason the quota tests go through the guards — the claim being
 * made is that the rule bites where people actually add colleagues.
 */
beforeEach(function (): void {
    Mail::fake();
});

function invite(Team $team, User $inviter, string $email, TeamRole $role = TeamRole::Member): TeamInvitation
{
    return app(TeamContext::class)->run($team, fn (): TeamInvitation => app(InviteTeamMemberAction::class)->handle(
        $team,
        new InviteMemberData(email: $email, role: $role),
        $inviter,
    ));
}

it('replaces Auth’s unlimited seat stub with the plan’s count', function (): void {
    expect(app(SeatLimitSource::class))->toBeInstanceOf(PlanSeatLimit::class);
});

it('refuses an invitation once the plan’s seats are taken', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter); // one seat, and the Owner is in it

    expect(fn () => invite($team, $owner, 'colleague@example.test'))
        ->toThrow(InvitationException::class);

    expect(TeamInvitation::query()->withoutGlobalScopes()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('counts a pending invitation as a seat', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Team); // three seats, one Owner

    invite($team, $owner, 'first@example.test');
    invite($team, $owner, 'second@example.test');

    // Owner + two invitations = three. Counting only accepted members would let this team send
    // ten invitations and discover the problem when the tenth person tried to sign in.
    expect(fn () => invite($team, $owner, 'third@example.test'))
        ->toThrow(InvitationException::class);
});

it('still lets somebody be re-invited to the seat they already hold', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Team);

    invite($team, $owner, 'first@example.test');
    invite($team, $owner, 'second@example.test');

    // The team is full, but this address is one of the occupants — resending must not be refused
    // for occupying the seat it is already occupying.
    $resent = invite($team, $owner, 'second@example.test');

    expect($resent->email)->toBe('second@example.test')
        ->and(TeamInvitation::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('does not count an expired invitation', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    app(TeamContext::class)->run($team, fn () => TeamInvitation::factory()->create([
        'team_id' => $team->getKey(),
        'email' => 'lapsed@example.test',
        'expires_at' => now()->subWeek(),
    ]));

    // It cannot be accepted, so it is not holding a seat. Counting it would make a team's last
    // seat unusable forever because somebody ignored an email in March.
    expect(app(SeatCounter::class)->occupied($team))->toBe(1);
});

it('re-checks the seat when the invitation is accepted, a week later', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Team);

    $invitation = invite($team, $owner, 'joiner@example.test');

    // In the week the invitation was valid, the team filled both remaining seats itself.
    Fixtures::addMember($team);
    Fixtures::addMember($team);

    $joiner = User::factory()->create(['email' => 'joiner@example.test']);

    expect(fn () => app(AcceptInvitationAction::class)->handle($invitation, $joiner))
        ->toThrow(InvitationException::class);

    // Refused, not consumed: the invitation still works once a seat is freed.
    expect($invitation->fresh()?->accepted_at)->toBeNull();
});

it('lets the last seat be taken by the person it was held for', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Team);

    Fixtures::addMember($team); // Owner + member = 2 of 3
    $invitation = invite($team, $owner, 'joiner@example.test');

    $joiner = User::factory()->create(['email' => 'joiner@example.test']);

    app(AcceptInvitationAction::class)->handle($invitation, $joiner);

    expect($joiner->fresh()?->belongsToTeam($team))->toBeTrue()
        ->and(app(SeatCounter::class)->remaining($team))->toBe(0);
});

it('never limits seats on an unlimited plan', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Enterprise);

    foreach (range(1, 12) as $n) {
        invite($team, $owner, "person{$n}@example.test");
    }

    expect(app(SeatCounter::class)->limitFor($team))->toBeNull()
        ->and(app(SeatCounter::class)->remaining($team))->toBeNull();
});

it('reports zero free seats rather than a negative number after a downgrade', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Scale);

    Fixtures::addMember($team);
    Fixtures::addMember($team);
    Fixtures::addMember($team);

    // Four members, then down to a one-seat plan. Nobody is removed — they simply cannot add
    // anybody else.
    $team->subscription()->forceFill([
        'stripe_price' => (string) config('billing.plans.starter.stripe_price'),
    ])->save();
    app(PlanResolver::class)->forget($team);

    expect(app(SeatCounter::class)->remaining($team))->toBe(0)
        ->and(app(SeatCounter::class)->members($team))->toBe(4);
});

it('keeps one team’s seats out of another’s count', function (): void {
    [$ownerA, $a] = Fixtures::team();
    [, $b] = Fixtures::team();

    Fixtures::subscribe($a, PlanKey::Team);
    Fixtures::subscribe($b, PlanKey::Team);

    invite($a, $ownerA, 'first@example.test');
    invite($a, $ownerA, 'second@example.test');

    // Team A is full. Team B has a single Owner and two seats free.
    expect(app(SeatCounter::class)->remaining($a))->toBe(0)
        ->and(app(SeatCounter::class)->remaining($b))->toBe(2);
});
