<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Support\ProtectsOperators;
use Illuminate\Support\Facades\DB;

/**
 * Mark an address verified (Phase 19a).
 *
 * The operation that looks like an exception to “we never grant access” and is not: the address was
 * already on the account, so nothing is being granted to a new one. It is how an operator unsticks
 * somebody whose verification mail bounced off a corporate filter.
 *
 * Returns false when the address was already verified, so the caller can say so rather than
 * reporting a success that did nothing.
 */
final class VerifyUserEmailAction
{
    use ProtectsOperators;

    public function __construct(private readonly AdminAudit $audit) {}

    /**
     * @return bool whether anything changed
     *
     * @throws AdminException
     */
    public function handle(User $target, User $actor): bool
    {
        $this->assertMayOperateOn($actor, $target);

        if ($target->hasVerifiedEmail()) {
            return false;
        }

        return DB::transaction(function () use ($target): bool {
            $target->markEmailAsVerified();

            $this->audit->record(
                'admin.user.email_verified',
                $target,
                [],
                $target->currentTeamId(),
            );

            return true;
        });
    }
}
