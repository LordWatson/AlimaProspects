<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Enums;

use App\Modules\LeadsWorkspace\Models\LeadActivity;

/**
 * What a {@see LeadActivity} records. The timeline aggregates events from across the platform, so
 * the cases name *what happened to the lead*, never which module emitted it — a later module can
 * add a case without the timeline knowing anything about it.
 *
 * Values are stable storage keys; {@see self::label()} is a translation key the screens pass through
 * `t()` (the workspace is EN/AR, ADR 0007 — so no English is ever stored on the row).
 */
enum LeadActivityType: string
{
    // What the platform did (Phase 10).
    case Discovered = 'discovered';
    case Enriched = 'enriched';
    case Scored = 'scored';

    // What the team did (Phase 10b).
    case StageChanged = 'stage_changed';
    case NoteAdded = 'note_added';
    case TaskCreated = 'task_created';
    case TaskCompleted = 'task_completed';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case TagsChanged = 'tags_changed';
    case Imported = 'imported';

    // What the team did with a signal (Signals, S4). One case, not two: converting a signal and
    // linking it to a company the team already had are the same act with a different outcome, which
    // the `attached` property carries — exactly how `Imported` carries `merged`.
    case SignalConverted = 'signal_converted';

    public function label(): string
    {
        return match ($this) {
            self::Discovered => 'Discovered',
            self::Enriched => 'Enriched',
            self::Scored => 'Graded',
            self::StageChanged => 'Moved',
            self::NoteAdded => 'Note added',
            self::TaskCreated => 'Task added',
            self::TaskCompleted => 'Task done',
            self::Assigned => 'Assigned',
            self::Unassigned => 'Unassigned',
            self::TagsChanged => 'Tags changed',
            self::Imported => 'Imported',
            self::SignalConverted => 'From a signal',
        };
    }

    /**
     * Whether the platform produced this entry on its own (a queued job) rather than a member doing
     * it. The timeline uses it to attribute the entry to Alima instead of a person.
     */
    public function isAutomatic(): bool
    {
        return match ($this) {
            self::Discovered, self::Enriched, self::Scored => true,
            self::StageChanged, self::NoteAdded, self::TaskCreated, self::TaskCompleted,
            self::Assigned, self::Unassigned, self::TagsChanged, self::Imported,
            self::SignalConverted => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
