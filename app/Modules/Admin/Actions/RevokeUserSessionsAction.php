<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Support\ProtectsOperators;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sign an account out everywhere (Phase 19a).
 *
 * **Two things, because either alone leaves a way in.** The stored sessions go, and the remember-me
 * token is rotated — without the second, a browser holding the cookie re-authenticates on its next
 * visit and the revocation was theatre.
 *
 * The session sweep is guarded on the table existing, because the session driver is configurable:
 * a `redis` or `cookie` deployment has no `sessions` table to sweep, in which case rotating the
 * remember token is what we can honestly do, and the count the caller reports says so.
 */
final class RevokeUserSessionsAction
{
    use ProtectsOperators;

    public function __construct(private readonly AdminAudit $audit) {}

    /**
     * @return int how many stored sessions were removed — 0 on a driver that keeps none, which is
     *             not the same as “nobody was signed in” and the caller's copy should not imply it is
     *
     * @throws AdminException
     */
    public function handle(User $target, User $actor): int
    {
        $this->assertMayOperateOn($actor, $target);

        return DB::transaction(function () use ($target): int {
            $swept = Schema::hasTable('sessions')
                ? DB::table('sessions')->where('user_id', $target->getKey())->delete()
                : 0;

            $target->forceFill(['remember_token' => Str::random(60)])->save();

            $this->audit->record(
                'admin.user.sessions_revoked',
                $target,
                ['sessions' => $swept],
                $target->currentTeamId(),
            );

            return $swept;
        });
    }
}
