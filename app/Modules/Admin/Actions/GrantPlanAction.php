<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Data\PlanGrantData;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Billing\Models\PlanGrant;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * Grant a team a plan without Stripe (Phase 19b).
 *
 * This is the Action behind three different conversations, which is why it is one Action rather
 * than three: an invoiced Enterprise customer whose contract says 25 seats and 40,000 leads; a
 * goodwill upgrade while somebody's billing is being sorted out; and an internal or partner tenant
 * nobody is charging at all.
 *
 * **Any grant already in force is revoked first**, in the same transaction. Grants are a history —
 * the record of what a tenant was entitled to and when is worth keeping — but only one can be in
 * force, and a resolver that silently picked "the newest of two overlapping grants" would make the
 * answer depend on a tiebreak rather than on a decision.
 *
 * Written inside the tenant's own context so `BelongsToTeam` stamps `team_id`, and the resolver's
 * memo is dropped afterwards so the same request sees the grant it just made.
 */
final class GrantPlanAction
{
    public function __construct(
        private readonly AdminAudit $audit,
        private readonly PlanResolver $plans,
        private readonly TeamContext $teamContext,
    ) {}

    public function handle(Team $team, PlanGrantData $data, User $operator): PlanGrant
    {
        return DB::transaction(function () use ($team, $data, $operator): PlanGrant {
            // The team row, locked. Two operators granting at once would otherwise each revoke what
            // they could see and insert, leaving two unrevoked grants — the exact state the revoke
            // above exists to prevent.
            Team::query()->whereKey($team->getKey())->lockForUpdate()->first();

            $grant = $this->teamContext->run($team, function () use ($team, $data, $operator): PlanGrant {
                // One **live or pending** grant at a time, not just one in force. `inForce()` left
                // a future-dated grant untouched, so granting Scale today and Enterprise from next
                // month and then Team today stacked two unrevoked rows — and which one wins next
                // month becomes a tiebreak rather than a decision. Revoked rather than deleted, so
                // what a tenant was entitled to last month is still answerable.
                PlanGrant::query()
                    ->where('team_id', $team->getKey())
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);

                return PlanGrant::query()->create([
                    'team_id' => $team->getKey(),
                    'plan' => $data->plan->value,
                    'seats' => $data->seats,
                    'leads' => $data->leads,
                    'credits' => $data->credits,
                    'starts_at' => $data->startsAt,
                    'ends_at' => $data->endsAt,
                    'note' => $data->note,
                    'granted_by' => $operator->getKey(),
                ]);
            });

            $this->audit->record(
                'admin.team.plan_granted',
                $grant,
                [
                    'plan' => $data->plan->value,
                    // The numbers, because a grant's whole point can be that it overrides them.
                    'seats' => $data->seats,
                    'leads' => $data->leads,
                    'credits' => $data->credits,
                    'ends_at' => $data->endsAt?->toIso8601String(),
                    'operator_id' => $operator->getKey(),
                ],
                (int) $team->getKey(),
            );

            $this->plans->forget($team);

            return $grant;
        });
    }
}
