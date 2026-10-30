<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Signals\Enums\SignalDismissReason;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\Signal;
use Illuminate\Support\Facades\DB;

/**
 * Clears a {@see Signal} off the feed (S4), with an optional reason.
 *
 * The reason is the whole point of storing anything here. A dismissed signal is cheap to delete and
 * worth almost nothing on its own, but "not an event" across a week of runs says the classifier is
 * too loose, while "not relevant" says the queries or the relevance floor are — which is how the
 * thresholds in `config/signals.php` get tuned on evidence rather than on feel. The retention sweep
 * (S6) removes the rows after `signals.retention.dismissed_days`.
 *
 * Idempotent: dismissing an already-dismissed signal updates the reason rather than erroring, so a
 * member who changes their mind about *why* doesn't have to restore it first. A **converted** signal
 * can't be dismissed — the lead exists and the feed card is history — and says so.
 *
 * Must be called inside the signal's team context.
 */
final class DismissSignalAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws SignalsException when the signal has already become a lead.
     */
    public function handle(Signal $signal, User $actor, ?SignalDismissReason $reason = null): Signal
    {
        return DB::transaction(function () use ($signal, $actor, $reason): Signal {
            /** @var Signal $locked */
            $locked = Signal::query()
                ->whereKey($signal->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isConverted()) {
                throw SignalsException::alreadyConverted();
            }

            $locked->forceFill([
                'status' => SignalStatus::Dismissed,
                'dismiss_reason' => $reason,
                'actioned_by' => $actor->getKey(),
                'actioned_at' => now(),
            ])->save();

            // The reason and the event type, never the headline or the person's name: the audit
            // trail records what the team decided, not a second copy of the article.
            $this->audit->log('signals.dismissed', $locked, [
                'event_type' => $locked->event_type->value,
                'reason' => $reason?->value,
            ], $actor);

            return $locked;
        });
    }
}
