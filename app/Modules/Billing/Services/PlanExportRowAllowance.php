<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Core\Models\Team;
use App\Modules\LeadsWorkspace\Contracts\ExportRowAllowance;

/**
 * The real {@see ExportRowAllowance} (PL6), bound over the Leads Workspace's permissive default.
 *
 * The **sixth** of the seams ADR 0012 established, and the thinnest: the reserve/commit/release
 * semantics, the locking and the idempotency all live in {@see UsageAllowance}, shared with every
 * other metered act. What this class owns is the one piece of knowledge the workspace must not
 * have — that an exported row spends `export_rows`.
 *
 * Bound **inside** `billing.enforce_plans`, unlike PL4's `CreditReserver`. That is the distinction
 * ADR 0031 drew: a reveal is money leaving the building per unit, so its reserve is a cost guard
 * that must stand whatever the switch says. An export costs us nothing, so a ceiling on it is a
 * plan feature and switching plan enforcement off takes it with everything else.
 */
final class PlanExportRowAllowance implements ExportRowAllowance
{
    public function __construct(private readonly UsageAllowance $allowance) {}

    public function remaining(Team $team): ?int
    {
        return $this->allowance->remaining($team, BillingMetric::ExportRows);
    }

    public function reserve(Team $team, int $rows): ?string
    {
        return $this->allowance->reserve($team, BillingMetric::ExportRows, $rows);
    }

    public function commit(string $handle, int $rows): void
    {
        if ($handle === '') {
            return;
        }

        $this->allowance->commit($handle, $rows);
    }

    public function release(string $handle): void
    {
        if ($handle === '') {
            return;
        }

        $this->allowance->release($handle);
    }
}
