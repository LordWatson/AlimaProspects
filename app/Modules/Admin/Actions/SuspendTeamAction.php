<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\TeamSuspension;
use App\Modules\Core\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Suspend a tenant (Phase 19a).
 *
 * What suspension is: sourcing, sending and spending stop. What it is not: a lockout. Members sign
 * in and read their own data exactly as before, which is deliberate twice over — it punishes the
 * colleague who did nothing to bolt the door on them, and a tenant who cannot reach their own data
 * cannot exercise their rights over it either.
 *
 * The reason is a required argument rather than an optional note. A suspension with no reason is one
 * nobody can safely lift: the next operator to look finds an account switched off by a colleague
 * who has gone home, and the only safe thing to do with it is nothing.
 *
 * Refuses to suspend twice, so a double-submitted form cannot overwrite the original timestamp and
 * reason with today's — the "since when" is the first thing anybody asks and the easiest to lose.
 */
final class SuspendTeamAction
{
    public function __construct(
        private readonly AdminAudit $audit,
        private readonly TeamSuspension $suspension,
    ) {}

    /**
     * @throws AdminException
     */
    public function handle(Team $team, string $reason, User $operator): Team
    {
        if ($team->isSuspended()) {
            throw AdminException::teamAlreadySuspended();
        }

        return DB::transaction(function () use ($team, $reason, $operator): Team {
            // Re-read under a lock, because the check above was made against a model loaded before
            // the transaction opened. Two operators on the same ticket — or one double-submitted
            // form — would otherwise both pass it, and the second write would overwrite the
            // original `suspended_at` and reason with its own. "Since when" is the first thing
            // anybody asks about a suspension and the easiest to lose.
            $locked = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isSuspended()) {
                throw AdminException::teamAlreadySuspended();
            }

            $team->forceFill([
                'suspended_at' => CarbonImmutable::now('UTC'),
                'suspension_reason' => $reason,
            ])->save();

            $this->audit->record(
                'admin.team.suspended',
                $team,
                ['operator_id' => $operator->getKey(), 'reason' => $reason],
                (int) $team->getKey(),
            );

            // The guards memoise per request and per job; a suspension applied mid-request must not
            // be contradicted by an answer cached a moment earlier.
            $this->suspension->forget();

            return $team;
        });
    }
}
