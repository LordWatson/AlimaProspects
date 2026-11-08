<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * Which way a `usage_ledger` row counts (PL4).
 *
 * A direction column rather than signed quantities, and the reason is readability under dispute: a
 * negative 3 could be a release, a refund or a correction, and a month a customer is querying is
 * exactly when nobody can remember which. Here the quantity is always positive and the row says
 * what it is.
 *
 * Six cases, and they fall into three jobs:
 *
 * - **Holding and settling:** {@see self::Reserve} holds allowance before money is spent,
 *   {@see self::Commit} turns a hold into consumption, {@see self::Release} gives one back.
 * - **Adding:** {@see self::Grant} is allowance the platform gave (an operator, a trial), and
 *   {@see self::TopUp} is allowance a team bought.
 * - **Fixing:** {@see self::Adjustment} is a human correcting the record, which is the one case
 *   that must be visible rather than silent.
 */
enum LedgerDirection: string
{
    /** Held, not yet spent. Counts against what is left; does not count as used. */
    case Reserve = 'reserve';

    /** Spent. The only direction that counts as **used**. */
    case Commit = 'commit';

    /** Handed back — a run that refused, a job that died, a reveal that failed. */
    case Release = 'release';

    /** Allowance the platform gave, outside any plan. */
    case Grant = 'grant';

    /** Allowance the team bought. */
    case TopUp = 'topup';

    /** A correction, by a person. Deliberately its own case so it cannot hide as a grant. */
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Reserve => 'Held',
            self::Commit => 'Used',
            self::Release => 'Released',
            self::Grant => 'Granted',
            self::TopUp => 'Topped up',
            self::Adjustment => 'Adjusted',
        };
    }

    /**
     * Whether this row **consumes** allowance.
     */
    public function consumes(): bool
    {
        return $this === self::Commit;
    }

    /**
     * Whether this row **adds** allowance on top of the plan's own.
     */
    public function adds(): bool
    {
        return $this === self::Grant || $this === self::TopUp || $this === self::Adjustment;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $direction): string => $direction->value, self::cases());
    }
}
