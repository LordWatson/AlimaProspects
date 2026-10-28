<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

final class TeamMembershipException extends DomainException
{
    protected string $errorCode = 'team.membership_invalid';

    public static function lastOwner(): self
    {
        $e = new self('A team must always have at least one owner.');
        $e->errorCode = 'team.last_owner';

        return $e;
    }

    public static function notAMember(): self
    {
        $e = new self('That person is not a member of this team.');
        $e->errorCode = 'team.not_a_member';

        return $e;
    }
}
