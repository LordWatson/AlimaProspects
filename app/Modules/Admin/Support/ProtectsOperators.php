<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Models\User;
use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Exceptions\AdminException;

/**
 * An account operation performed **on another operator** is a different act, and needs a different
 * permission (Phase 19a).
 *
 * The hole this closes: `users.manage` is the support engineer's ability — reset a password, clear a
 * second factor, revoke sessions and tokens. Every one of those is safe to do *for a customer* and
 * none of them is safe to do *to a colleague with more power than you*. Clearing a super admin's
 * second factor leaves `EnsureAdminTwoFactor` sending them to re-enrol, which is a lower role
 * silently taking the second lock off a higher one; revoking their sessions and tokens is a denial
 * of service against the person who would notice.
 *
 * So: operating on a platform operator is `super_admin` only. There has to be *somebody* who can
 * help an operator who has lost their phone, and that somebody is the role that already holds every
 * other platform power.
 */
trait ProtectsOperators
{
    /**
     * @throws AdminException when `$target` is an operator and `$actor` is not a super admin
     */
    protected function assertMayOperateOn(User $actor, User $target): void
    {
        if (! $target->isPlatformAdmin()) {
            return;
        }

        if ($actor->adminRole() === AdminRole::SuperAdmin) {
            return;
        }

        throw AdminException::targetIsOperator();
    }
}
