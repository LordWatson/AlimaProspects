<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes a note on a lead (Phase 10b), with its timeline entry and audit row in the same
 * transaction.
 *
 * The audit entry records **that** a note was written and how long it was, never the note itself:
 * a note is the team's own words about a company and may well contain personal detail, which the
 * audit trail has no business duplicating (CLAUDE.md logging rules).
 *
 * Must be called inside the lead's team context.
 */
final class CreateLeadNoteAction
{
    public function __construct(
        private readonly RecordLeadActivityAction $record,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Lead $lead, User $author, string $body): LeadNote
    {
        return DB::transaction(function () use ($lead, $author, $body): LeadNote {
            $note = LeadNote::query()->create([
                'team_id' => $lead->team_id,
                'lead_id' => $lead->getKey(),
                'user_id' => $author->getKey(),
                'body' => trim($body),
            ]);

            $this->record->handle(
                lead: $lead,
                type: LeadActivityType::NoteAdded,
                subject: $note,
                // An excerpt, so the timeline reads as something rather than as "a note was added"
                // — short enough that the timeline isn't a second copy of the note.
                properties: ['excerpt' => Str::limit($note->body, 120)],
                actor: $author,
            );

            $this->audit->log('leads.note_added', $note, ['length' => Str::length($note->body)], $author);

            return $note;
        });
    }
}
