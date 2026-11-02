<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Billing\Enums\BillingMode;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Change how a tenant is billed (Phase 19b).
 *
 * **It does not touch entitlement**, and that separation is the feature. Moving a team to `manual`
 * or `disabled` stops checkout, the portal, plan swaps and metering; what the team is *allowed to
 * do* comes from `PlanResolver`, which reads a grant and then a live subscription. An operator who
 * switches a paying customer to `manual` without granting them a plan has moved them onto the
 * default plan, which is a real outcome and the reason the screen warns about it rather than the
 * reason this Action refuses.
 *
 * The resolver's memo is dropped afterwards, so the same request does not go on answering with the
 * mode the team had a moment ago — the contract `PlanResolver::forget()` exists for.
 */
final class SetBillingModeAction
{
    public function __construct(
        private readonly AdminAudit $audit,
        private readonly PlanResolver $plans,
    ) {}

    public function handle(Team $team, BillingMode $mode, User $operator): Team
    {
        $before = BillingMode::of($team);

        if ($before === $mode) {
            return $team;
        }

        return DB::transaction(function () use ($team, $mode, $before, $operator): Team {
            $team->forceFill(['billing_mode' => $mode->value])->save();

            $this->audit->record(
                'admin.team.billing_mode_changed',
                $team,
                ['from' => $before->value, 'to' => $mode->value, 'operator_id' => $operator->getKey()],
                (int) $team->getKey(),
            );

            $this->plans->forget($team);

            return $team;
        });
    }
}
