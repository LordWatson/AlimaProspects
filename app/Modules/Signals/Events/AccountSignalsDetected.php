<?php

declare(strict_types=1);

namespace App\Modules\Signals\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A run found events about companies the team is already working (S10).
 *
 * Separate from {@see SignalsDetected}, which announces *new* signals to the whole team. This one is
 * about **accounts somebody owns**, and the difference decides who hears about it: a discovery
 * signal is news for the team, an event on a lead is news for the person working that lead.
 *
 * **Carries a count, not the signals.** The same arrangement `SignalsDetected` has, for the same
 * reason: an event per signal would fan out into a notification per signal, and the one rule
 * CLAUDE.md states about notifications is that anything which would fan out per record is
 * aggregated first. The listener re-reads the run, groups by owner and sends one each.
 */
final class AccountSignalsDetected
{
    use Dispatchable;

    public function __construct(
        public readonly int $teamId,
        public readonly int $runId,
        public readonly int $count,
    ) {}
}
