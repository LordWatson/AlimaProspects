<?php

declare(strict_types=1);

namespace App\Modules\Signals\Events;

use App\Modules\Signals\Enums\SignalDismissReason;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired (after commit) when a member brings a dismissed signal back (S12).
 *
 * It exists so the learning can be **undone**. A mis-click that taught `signal_source_stats` an
 * outlet was worthless, and then was immediately corrected on screen, would otherwise leave the
 * lesson behind — and the tuning advisor would eventually propose excluding a source on the
 * strength of dismissals the team had already taken back. So the restore carries the reason the
 * dismissal had, which is the only way the listener knows which counter to decrement.
 *
 * The reason comes from the event rather than the row because the row no longer has one: restoring
 * clears `dismiss_reason` (S4), so by the time a listener reads the signal the evidence is gone.
 */
final class SignalRestored
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $signalId,
        /** The reason the dismissal carried, captured before it was cleared. */
        public readonly ?SignalDismissReason $reason = null,
    ) {}
}
