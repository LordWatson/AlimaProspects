<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\TeamSuspension;
use App\Modules\Core\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Lift a suspension (Phase 19a).
 *
 * The reason the team was suspended for is **kept**, not cleared. A lifted suspension is a thing
 * that happened, and the next operator to look at the account should be able to see that it once
 * stopped and why — which is exactly the history a `null` here would throw away. The audit entry
 * records the lift; the column records that there was something to lift.
 */
final class RestoreTeamAction
{
    public function __construct(
        private readonly AdminAudit $audit,
        private readonly TeamSuspension $suspension,
    ) {}

    /**
     * @throws AdminException
     */
    public function handle(Team $team, User $operator, ?string $note = null): Team
    {
        if (! $team->isSuspended()) {
            throw AdminException::teamNotSuspended();
        }

        return DB::transaction(function () use ($team, $operator, $note): Team {
            // Locked and re-checked, for the same reason as `SuspendTeamAction`: the check above
            // ran against a model loaded before the transaction.
            $locked = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isSuspended()) {
                throw AdminException::teamNotSuspended();
            }

            $previousReason = $team->suspension_reason;

            $team->forceFill(['suspended_at' => null])->save();

            $this->audit->record(
                'admin.team.restored',
                $team,
                array_filter([
                    'operator_id' => $operator->getKey(),
                    'previous_reason' => $previousReason,
                    'note' => $note,
                ], static fn (mixed $value): bool => $value !== null),
                (int) $team->getKey(),
            );

            $this->suspension->forget();

            return $team;
        });
    }
}
