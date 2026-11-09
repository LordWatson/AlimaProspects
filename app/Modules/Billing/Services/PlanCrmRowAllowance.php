<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Core\Models\Team;
use App\Modules\Integrations\Contracts\CrmRowAllowance;

/**
 * The real {@see CrmRowAllowance} (PL7), bound over the Integrations module's permissive default.
 *
 * The **seventh** ADR 0012 seam and as thin as the sixth: the locking, the recount inside it and
 * the idempotent settle all live in {@see UsageAllowance}. What this class owns is the one piece of
 * knowledge Integrations must not have — that a pushed record spends `crm_sync`.
 *
 * Bound **inside** `billing.enforce_plans`, beside PL6's: a CRM push costs us nothing per record,
 * so a ceiling on it is a plan feature and switching plan enforcement off takes it along with
 * everything else that is one.
 */
final class PlanCrmRowAllowance implements CrmRowAllowance
{
    public function __construct(private readonly UsageAllowance $allowance) {}

    public function remaining(Team $team): ?int
    {
        return $this->allowance->remaining($team, BillingMetric::CrmSync);
    }

    public function reserve(Team $team, int $records): ?string
    {
        return $this->allowance->reserve($team, BillingMetric::CrmSync, $records);
    }

    public function commit(string $handle, int $records): void
    {
        if ($handle === '') {
            return;
        }

        $this->allowance->commit($handle, $records);
    }

    public function release(string $handle): void
    {
        if ($handle === '') {
            return;
        }

        $this->allowance->release($handle);
    }
}
