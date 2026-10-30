<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

final class InvitationException extends DomainException
{
    protected string $errorCode = 'invitation.invalid';

    public static function expired(): self
    {
        $e = new self('This invitation has expired. Ask the team to send a fresh one.');
        $e->errorCode = 'invitation.expired';

        return $e;
    }

    public static function alreadyAccepted(): self
    {
        $e = new self('This invitation has already been accepted.');
        $e->errorCode = 'invitation.already_accepted';

        return $e;
    }

    public static function emailMismatch(): self
    {
        $e = new self('This invitation was sent to a different email address. Sign in with that address to accept it.');
        $e->errorCode = 'invitation.email_mismatch';
        $e->status = 403;

        return $e;
    }

    /**
     * Every seat the team's plan includes is taken — by a member or by a live invitation.
     *
     * Phrased as a choice rather than a wall: the two ways out are a bigger plan and a seat freed
     * up, and a message that only mentions upgrading reads as a sales pitch at the moment somebody
     * is trying to do their job.
     */
    public static function noSeatsAvailable(int $seats): self
    {
        $e = new self(
            $seats === 1
                ? 'Your plan includes one seat. Upgrade to invite colleagues.'
                : "Your plan includes {$seats} seats and they are all taken. Upgrade the plan, or remove a member or a pending invitation first."
        );
        $e->errorCode = 'invitation.no_seats_available';
        $e->status = 402;

        return $e;
    }

    public static function alreadyMember(): self
    {
        $e = new self('You are already a member of this team.');
        $e->errorCode = 'invitation.already_member';

        return $e;
    }
}
