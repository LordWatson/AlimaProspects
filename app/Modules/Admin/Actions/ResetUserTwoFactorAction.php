<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Support\ProtectsOperators;
use Illuminate\Support\Facades\DB;

/**
 * Clear somebody's second factor so they can enrol a new device (Phase 19a).
 *
 * For the handset that went in the sea — a real and frequent support call with no self-service
 * answer, because the recovery codes are in a drawer at home and the whole point of the second
 * factor is that we cannot read theirs.
 *
 * The write and the audit entry are one transaction. Two commits would mean a failed audit leaving
 * an **unaudited** 2FA reset on the record, which is the one outcome worse than not doing it.
 */
final class ResetUserTwoFactorAction
{
    use ProtectsOperators;

    public function __construct(private readonly AdminAudit $audit) {}

    /**
     * Clears the secret, the recovery codes and the confirmation together: leaving any one of them
     * behind is a half-enrolled account that Fortify reads inconsistently.
     *
     * @throws AdminException
     */
    public function handle(User $target, User $actor): void
    {
        $this->assertMayOperateOn($actor, $target);

        DB::transaction(function () use ($target): void {
            $target->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $this->audit->record(
                'admin.user.two_factor_reset',
                $target,
                [],
                $target->currentTeamId(),
            );
        });
    }
}
