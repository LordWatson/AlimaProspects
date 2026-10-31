<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Billing\Models\PlanGrant;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * End a plan grant (Phase 19b).
 *
 * **Revoked, not deleted.** The row is the record of what a tenant was entitled to and when, and a
 * grant that vanishes takes the answer to "why was this team on Scale in March?" with it.
 *
 * Worth knowing before using it: revoking a `manual` team's only grant drops them to
 * `billing.default_plan` — there is no Stripe subscription underneath to fall back to. That is
 * correct (an invoiced customer whose contract has ended is not entitled to anything) and it is
 * also abrupt, which is why the screen says so and why `ends_at` on the grant is the gentler lever.
 */
final class RevokePlanGrantAction
{
    public function __construct(
        private readonly AdminAudit $audit,
        private readonly PlanResolver $plans,
    ) {}

    public function handle(PlanGrant $grant, User $operator, ?string $reason = null): PlanGrant
    {
        // Already revoked: say nothing more. Restamping `revoked_at` would move the date a tenant's
        // entitlement actually ended — which is the one thing the kept row exists to answer — and
        // write a second audit entry for a decision somebody made once.
        if ($grant->revoked_at !== null) {
            return $grant;
        }

        return DB::transaction(function () use ($grant, $operator, $reason): PlanGrant {
            $grant->forceFill(['revoked_at' => now()])->save();

            $this->audit->record(
                'admin.team.plan_grant_revoked',
                $grant,
                array_filter([
                    'plan' => $grant->plan->value,
                    'reason' => $reason,
                    'operator_id' => $operator->getKey(),
                ], static fn (mixed $value): bool => $value !== null),
                (int) $grant->team_id,
            );

            $team = Team::query()->find($grant->team_id);

            if ($team instanceof Team) {
                $this->plans->forget($team);
            }

            return $grant;
        });
    }
}
