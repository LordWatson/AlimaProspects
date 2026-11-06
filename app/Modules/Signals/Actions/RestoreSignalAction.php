<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Events\SignalRestored;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Services\SignalClusterer;
use Illuminate\Support\Facades\DB;

/**
 * Puts a dismissed {@see Signal} back on the feed (S4) — the undo for a mis-click, and the reason
 * dismissal doesn't delete the row outright.
 *
 * Dismissed → New only. A **converted** signal is not restorable: the lead it made is real, sitting
 * in someone's pipeline, and "restoring" the card would invite a second conversion of the same
 * event. Deleting that lead is the way back, and it is deliberately a separate, explicit act.
 *
 * The dismiss reason is cleared, so a signal dismissed as "not an event" and then restored doesn't
 * keep arguing against itself in the tuning data. The actioned-by/at stamps are cleared too: the
 * signal is new again, and a `New` row carrying someone's name reads as though they did something
 * to it.
 *
 * Idempotent: restoring a signal that is already New is a no-op that returns it unchanged.
 *
 * Must be called inside the signal's team context.
 */
final class RestoreSignalAction
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SignalClusterer $clusterer,
    ) {}

    /**
     * @throws SignalsException when the signal has already become a lead.
     */
    public function handle(Signal $signal, User $actor): Signal
    {
        return DB::transaction(function () use ($signal, $actor): Signal {
            /** @var Signal $locked */
            $locked = Signal::query()
                ->whereKey($signal->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isConverted()) {
                throw SignalsException::alreadyConverted();
            }

            if ($locked->status === SignalStatus::New) {
                return $locked;
            }

            // Captured **before** the update clears it. Restoring drops `dismiss_reason` (S4), so
            // by the time a listener reads the row the evidence of what is being taken back is
            // gone — and the ledger has to know which counter to decrement.
            $reason = $locked->dismiss_reason;

            $locked->forceFill([
                'status' => SignalStatus::New,
                'dismiss_reason' => null,
                'actioned_by' => null,
                'actioned_at' => null,
            ])->save();

            // The rest of the cluster comes back with it (S11), for the same reason it went away
            // together: one event, one status.
            $alsoRestored = $this->clusterer->propagateStatus($locked);

            $this->audit->log('signals.restored', $locked, [
                'event_type' => $locked->event_key,
                'cluster_members_restored' => $alsoRestored,
            ], $actor);

            $teamId = (int) $locked->team_id;
            $signalId = (int) $locked->getKey();

            DB::afterCommit(static function () use ($teamId, $signalId, $reason): void {
                event(new SignalRestored($teamId, $signalId, $reason));
            });

            return $locked;
        });
    }
}
