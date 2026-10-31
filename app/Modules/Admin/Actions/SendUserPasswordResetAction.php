<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Support\ProtectsOperators;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Email somebody a password reset link (Phase 19a).
 *
 * **It sends a link; it never sets a password.** That is the rule running through every account
 * operation in the panel, and it is why there is no field anywhere in the admin UI that looks as
 * though it could set one.
 *
 * Two things this gets right that the obvious version does not:
 *
 * - **The broker's verdict is reported, not assumed.** `sendResetLink` can answer
 *   `RESET_THROTTLED` (somebody already asked within the throttle window) or `INVALID_USER`. The
 *   naive version flashes "a link is on its way" either way, so an operator tells a customer to
 *   check their inbox for a mail that was never sent, and the customer waits.
 * - **The external call happens outside the transaction**, which the mail send plainly is
 *   (CLAUDE.md §2: never call out from inside one). The only write of ours here is the audit entry,
 *   and it records what the broker actually did.
 */
final class SendUserPasswordResetAction
{
    use ProtectsOperators;

    public function __construct(private readonly AdminAudit $audit) {}

    /**
     * @return string the broker's status constant — `Password::RESET_LINK_SENT` on success
     *
     * @throws AdminException
     */
    public function handle(User $target, User $actor): string
    {
        $this->assertMayOperateOn($actor, $target);

        $status = Password::broker()->sendResetLink(['email' => $target->email]);

        DB::transaction(fn () => $this->audit->record(
            'admin.user.password_reset_sent',
            $target,
            ['status' => $status],
            $target->currentTeamId(),
        ));

        return $status;
    }
}
