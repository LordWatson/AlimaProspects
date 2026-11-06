<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use Illuminate\Support\Facades\DB;

/**
 * Deletes one of a team's signal types (S7, ADR 0018), transactionally and audited.
 *
 * **Refused while a watch still uses it.** Deleting it anyway would silently change what that watch
 * looks for, and a watch that quietly stops finding a kind of event is the worst failure this
 * feature has: nobody notices, because the absence of a signal looks exactly like a quiet week.
 * `SignalsException::definitionInUse()` names the count, and pausing the type is offered as the
 * thing the member probably wanted.
 *
 * **Soft, not hard, and the signals stay.** Every signal this type classified carries a copy of its
 * key in `event_key` and points at it with a `nullOnDelete` FK, so the feed, the reporting
 * breakdown and a converted lead's origin panel all keep working after the row goes (ADR 0018 §4).
 * Soft-deleting keeps the FK intact as well, so even the attribution survives — which is why this
 * refuses to be the place that destroys a team's history.
 */
final class DeleteSignalDefinitionAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(SignalDefinition $definition, User $actor): void
    {
        if ($definition->isPreset()) {
            throw SignalsException::presetNotEditable();
        }

        DB::transaction(function () use ($definition, $actor): void {
            // Counted inside the transaction: a watch could otherwise be attached between the check
            // and the delete, and the whole point of the refusal is that it holds.
            $watches = $definition->watches()->count();

            if ($watches > 0) {
                throw SignalsException::definitionInUse($watches);
            }

            $this->audit->log('signals.definition_deleted', $definition, [
                'key' => $definition->key,
                'category' => $definition->category->value,
                // The only place this number survives the delete — the same reasoning as
                // `DeleteSignalWatchAction`'s cascade counts.
                'signals_kept' => $definition->signals()->count(),
                'preset_key' => $definition->preset_key,
            ], $actor);

            $definition->delete();
        });
    }
}
