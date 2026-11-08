<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Models\EnrichmentAttempt;
use DateTimeInterface;

/**
 * What a team's contact-data vendors have cost us over a window, answered by the module that owns
 * the table (PL4).
 *
 * The Enrichment counterpart of `Signals\Services\SignalUsageCounter`,
 * `LeadSourcing\Services\LeadUsageCounter` and `AiPlatform\Services\AiUsageLogger`, and the same
 * division of labour: this module owns the ledger and answers the question, Billing's margin guard
 * turns the answer into a refusal, and neither learns anything about the other.
 *
 * A **read over a ledger**, never a counter (ADR 0012). `enrichment_attempts.cost_micros` is
 * written once per provider call by {@see AttemptRecorder}, from what the call actually cost, so a
 * figure here cannot be stale through a forgotten increment or doubled through a retried one.
 *
 * **Every attempt counts, whatever its outcome.** A provider that charged us for a search returning
 * nothing has still charged us, and a margin guard that only counted successes would be blind to
 * the exact failure it exists to catch: a vendor billing per attempt against a lead list that
 * matches nothing.
 *
 * `withoutGlobalScopes()` with an explicit `team_id`, because the caller is a guard inside a queued
 * job and an admin screen with no team in context.
 */
final class EnrichmentCostCounter
{
    /**
     * Vendor spend in micro-units between `$from` (inclusive) and `$to` (exclusive).
     *
     * One figure, not split by currency: the vendors are billed in their own currencies and the
     * column does not record which. The margin guard states that shortcut; the admin margin screen
     * is the surface that converts (`Admin\Services\Platform\PlatformMoney`).
     */
    public function vendorCostMicrosBetween(Team $team, DateTimeInterface $from, DateTimeInterface $to): int
    {
        return (int) EnrichmentAttempt::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            // Half-open, like `AiUsageLogger::usedMicrosBetween()`: `whereBetween` is inclusive at
            // both ends, which counts the boundary row in two consecutive periods.
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->sum('cost_micros');
    }
}
