<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Support\ProtectsOperators;
use Illuminate\Support\Facades\DB;

/**
 * Revoke every API token a person holds (Phase 19a) — the lever for a key that has leaked.
 *
 * Counted before the delete rather than after, because the count is what the operator is told and
 * “revoked 0 tokens” after a successful revocation would read as a failure.
 */
final class RevokeUserTokensAction
{
    use ProtectsOperators;

    public function __construct(private readonly AdminAudit $audit) {}

    /**
     * @return int how many tokens were revoked
     *
     * @throws AdminException
     */
    public function handle(User $target, User $actor): int
    {
        $this->assertMayOperateOn($actor, $target);

        return DB::transaction(function () use ($target): int {
            $count = $target->tokens()->count();

            $target->tokens()->delete();

            $this->audit->record(
                'admin.user.tokens_revoked',
                $target,
                ['tokens' => $count],
                $target->currentTeamId(),
            );

            return $count;
        });
    }
}
