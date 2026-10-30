<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Contracts\SeatLimitSource;
use App\Modules\Auth\Exceptions\InvitationException;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a team's seats are doing: how many are taken, how many the plan includes, and whether there
 * is room for one more.
 *
 * **A pending invitation occupies a seat.** Counting only accepted members would let a three-seat
 * team send ten invitations and discover the problem when the tenth person tried to sign in — a
 * seat limit that is only enforced on acceptance is one the person who hits it did not cause and
 * cannot fix. An expired invitation does not count: it cannot be accepted, so it is not holding
 * anything.
 *
 * The limit itself comes from the {@see SeatLimitSource} seam, so this class never knows what a
 * plan is.
 */
final class SeatCounter
{
    public function __construct(private readonly SeatLimitSource $limits) {}

    /**
     * Seats included in the team's plan, or null when unlimited.
     */
    public function limitFor(Team $team): ?int
    {
        return $this->limits->seatsFor($team);
    }

    /**
     * Seats currently spoken for: members plus live invitations.
     */
    public function occupied(Team $team): int
    {
        return $this->members($team) + $this->pendingInvitations($team);
    }

    public function members(Team $team): int
    {
        return $team->users()->count();
    }

    public function pendingInvitations(Team $team): int
    {
        return $this->pending($team)->count();
    }

    /**
     * Seats still free, or null when unlimited. Never negative: a team that has more members than
     * its plan allows — after a downgrade, or after a plan's seat count was reduced — has zero
     * free, not minus two.
     */
    public function remaining(Team $team): ?int
    {
        $limit = $this->limitFor($team);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->occupied($team));
    }

    /**
     * Refuse the invitation when the plan has no room for it.
     *
     * `$excluding` is the email of an address that already holds a pending invitation and is being
     * re-invited: re-sending an invitation must not be refused for occupying the seat it already
     * occupies.
     *
     * @throws InvitationException when every seat is taken.
     */
    public function ensureSeatAvailable(Team $team, ?string $excluding = null): void
    {
        $limit = $this->limitFor($team);

        if ($limit === null) {
            return;
        }

        $occupied = $this->occupied($team);

        if ($excluding !== null && $this->hasPendingInvitation($team, $excluding)) {
            $occupied--;
        }

        if ($occupied >= $limit) {
            throw InvitationException::noSeatsAvailable($limit);
        }
    }

    private function hasPendingInvitation(Team $team, string $email): bool
    {
        return $this->pending($team)->where('email', $email)->exists();
    }

    /**
     * Invitations that could still be accepted — the same definition as
     * {@see TeamInvitation::isPending()}, expressed as a query.
     *
     * A null `expires_at` counts as pending, matching the model: the column is nullable and the
     * model reads "no expiry" as "never expires", so a query that required a future date would
     * quietly not count a seat the product would still let somebody take.
     *
     * `withoutGlobalScopes()` with an explicit `team_id` rather than relying on the tenant scope:
     * this is called while accepting an invitation, when the active team is not yet the team being
     * joined, and a scope that silently returned zero rows would make every seat look free.
     *
     * @return Builder<TeamInvitation>
     */
    private function pending(Team $team): Builder
    {
        return TeamInvitation::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->whereNull('accepted_at')
            ->where(static function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
