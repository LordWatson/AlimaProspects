<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Contracts;

use App\Modules\Core\Models\Team;
use App\Modules\LeadsWorkspace\Services\UnmeteredExportRows;

/**
 * How many rows this team may still take out this month, and the booking that says they did (PL6).
 *
 * The **sixth** seam of the kind ADR 0012 established — after `CreditSource`, `LeadQuotaSource`,
 * `SeatLimitSource`, `SignalQuotaSource` and `CreditReserver` — and the same arrangement: this
 * module declares the contract with a default that works on its own, Billing binds the real one
 * over the top, and no module imports `App\Modules\Billing`.
 *
 * **Reserve, then commit**, exactly as PL3's `CreditReserver` does and for a related reason. An
 * export costs us nothing per row, so this is not a cost guard; what it protects is the promise
 * `BillingMetric::ExportRows` already makes, which is that an export allowance is a monthly ceiling
 * with no overage and nothing to buy. The reserve happens when the member asks — a queued export
 * that discovered the refusal in a worker would tell them by failed notification ten minutes later,
 * and two large exports asked for at once would both be told there was room.
 *
 * The default ({@see UnmeteredExportRows}) is **permissive**, like the four ADR 0012 stubs and
 * unlike `StubSignalQuotaSource`: there is no cost floor here to protect, and a team on no plan at
 * all taking their own data out is not a risk worth a refusal.
 */
interface ExportRowAllowance
{
    /**
     * Rows left this period, or null for unlimited.
     *
     * Read by the screen so a refusal can say what the ceiling was rather than just "no" — and
     * read before the reserve, so an export too big to run is refused before a file is created.
     */
    public function remaining(Team $team): ?int;

    /**
     * Hold `$rows` of allowance, returning a handle, or null when there is not enough left.
     *
     * Null rather than an exception: a team at its ceiling is an ordinary answer a screen explains,
     * not an error in the logs.
     */
    public function reserve(Team $team, int $rows): ?string;

    /**
     * Settle a handle against the rows actually written, releasing the rest.
     *
     * Idempotent: the generator runs in a job that can be retried, and a double commit must not
     * bill twice.
     */
    public function commit(string $handle, int $rows): void;

    /**
     * Give back a handle nothing was written against — a job that failed, an export abandoned.
     */
    public function release(string $handle): void;
}
