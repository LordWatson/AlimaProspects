<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Contracts;

use App\Modules\Core\Models\Team;
use App\Modules\Integrations\Services\UnmeteredCrmRows;

/**
 * How many records this team may still push to a CRM this month (PL7).
 *
 * The **seventh** seam of the kind ADR 0012 established, after `CreditSource`, `LeadQuotaSource`,
 * `SeatLimitSource`, `SignalQuotaSource`, `CreditReserver` and PL6's `ExportRowAllowance`. Same
 * arrangement every time: this module declares the contract with a default that works on its own,
 * Billing binds the real one over the top, and nothing outside Billing imports it.
 *
 * `BillingMetric::CrmSync` has existed since PL4 with an allowance on every plan and nothing
 * spending it, exactly as `ExportRows` did before PL6. This is what spends it.
 *
 * **One unit per record that actually moved.** A skip because nothing had changed, and a refusal
 * because the company is on the do-not-contact list, both release the hold: neither created
 * anything in anybody's CRM, and charging for the framework being careful would make the
 * `synced_hash` optimisation cost a team money.
 *
 * Bound **inside** `billing.enforce_plans`, like PL6's and unlike PL4's `CreditReserver`: a push to
 * a CRM costs us nothing per record, so a ceiling on it is a plan feature rather than a cost guard.
 *
 * The default ({@see UnmeteredCrmRows}) is permissive, for the reason PL6's is.
 */
interface CrmRowAllowance
{
    /**
     * Records left this period, or null for unlimited. For the screen.
     */
    public function remaining(Team $team): ?int;

    /**
     * Hold `$records`, returning a handle, or null when there is not enough left.
     *
     * Null rather than an exception: a team at its ceiling is an outcome the sync log records, not
     * an error in the logs.
     */
    public function reserve(Team $team, int $records): ?string;

    /**
     * Settle a handle against what actually moved, releasing the rest. Idempotent — the push runs
     * in a job that can be retried.
     */
    public function commit(string $handle, int $records): void;

    /**
     * Give back a handle nothing moved against.
     */
    public function release(string $handle): void;
}
