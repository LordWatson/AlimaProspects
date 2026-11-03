<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Signals\Models\SignalPlaybook;
use Illuminate\Support\Facades\DB;

/**
 * Removes one playbook (S14), and **keeps its run log**.
 *
 * That is the whole of the interesting part. The rule is deleted outright — there is no soft delete
 * here, unlike a signal definition, because nothing refers to a playbook the way a signal refers to
 * the type that classified it. But `signal_playbook_runs.signal_playbook_id` is `nullOnDelete` with
 * the name copied onto the row, so the record of what this rule did to somebody's pipeline survives
 * it. Deleting a rule must not erase the explanation for why a lead is in Engaged with a draft
 * attached.
 */
final class DeleteSignalPlaybookAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Must be called inside the playbook's team context.
     */
    public function handle(SignalPlaybook $playbook, User $actor): void
    {
        DB::transaction(function () use ($playbook, $actor): void {
            // Audited **before** the delete, so the subject still exists to be named.
            $this->audit->log('signals.playbook_deleted', $playbook, [
                'name' => $playbook->name,
                'mode' => $playbook->mode->value,
            ], $actor);

            $playbook->delete();
        });
    }
}
