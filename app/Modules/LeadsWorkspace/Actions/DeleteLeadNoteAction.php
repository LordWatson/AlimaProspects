<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use Illuminate\Support\Facades\DB;

/**
 * Removes a note (Phase 10b), together with the timeline entry that quoted it — the excerpt on the
 * timeline is a copy of the note's text, so leaving it behind would mean a deleted note was still
 * readable on the lead. The audit trail keeps the fact of the deletion.
 */
final class DeleteLeadNoteAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(LeadNote $note, User $actor): void
    {
        DB::transaction(function () use ($note, $actor): void {
            $this->audit->log('leads.note_deleted', $note, ['lead_id' => $note->lead_id], $actor);

            LeadActivity::query()
                ->where('team_id', $note->team_id)
                ->where('subject_type', $note->getMorphClass())
                ->where('subject_id', $note->getKey())
                ->delete();

            $note->delete();
        });
    }
}
