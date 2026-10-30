<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The window an allowance is measured over: the **calendar month in UTC**.
 *
 * Not the Stripe invoice period, and the difference is worth stating because it looks like an
 * oversight. Anchoring allowances to the invoice date would mean a team that subscribed on the 29th
 * gets a reset on the 29th — fine — but also that the reset date moves when they change plan, that
 * a trial and a subscription measure different windows, and that every usage query needs a round
 * trip to Stripe to find out where the window starts. A calendar month is the thing a customer can
 * predict without reading an invoice, it is what the AI ledger has measured since Phase 5, and
 * overage is reported to Stripe as it accrues, so it still lands on whatever invoice is open.
 *
 * UTC is not a detail either: the bounds reach a `where created_at >= ?` on tables written by
 * workers in UTC, so constructing them in a team's timezone would shift every team's reset by its
 * offset and double-count the overlap (CLAUDE.md §2).
 */
final readonly class BillingPeriod
{
    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    /**
     * The period containing `$at` (now, by default).
     */
    public static function containing(?DateTimeInterface $at = null): self
    {
        $moment = $at instanceof DateTimeInterface
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        $start = $moment->startOfMonth();

        return new self($start, $start->addMonth());
    }

    /**
     * The period before this one — what an invoice is settled against once a month closes.
     */
    public function previous(): self
    {
        return self::containing($this->start->subDay());
    }

    public function contains(DateTimeInterface $at): bool
    {
        $moment = CarbonImmutable::instance($at)->utc();

        return $moment >= $this->start && $moment < $this->end;
    }

    /**
     * How far through the period we are, 0–100. What the UI needs to say whether a team is burning
     * its allowance faster than the month is passing.
     */
    public function elapsedPercent(?DateTimeInterface $at = null): int
    {
        $moment = $at instanceof DateTimeInterface
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        $total = $this->end->getTimestamp() - $this->start->getTimestamp();

        if ($total <= 0) {
            return 100;
        }

        $elapsed = $moment->getTimestamp() - $this->start->getTimestamp();

        return (int) max(0, min(100, (int) round($elapsed / $total * 100)));
    }
}
