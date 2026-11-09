<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Enums;

use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Outreach\Models\ContactAttempt;

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
    case ContactAdded = 'contact_added';
    case TaskCreated = 'task_created';
    case TaskCompleted = 'task_completed';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case TagsChanged = 'tags_changed';
    case Imported = 'imported';
    /**
     * A member added this company themselves (Phase 10c). Distinct from {@see self::Imported}
     * because "I met them at an event and typed them in" and "I uploaded my spreadsheet" are
     * different stories about where a lead came from, and the timeline's job is to tell the right
     * one. Carries `merged` exactly as `Imported` does, for the case where the team already had
     * the company.
     */
    case Created = 'created';

    /**
     * The team approached this company (Outreach, Phase 11). One case for all three channels and
     * both routes — an email Alima sent and a call the member logged — because from the lead's
     * side they are the same event: somebody got in touch. The `channel`, `outcome` and `sent`
     * properties carry the rest, and the entry is subjected to the {@see ContactAttempt},
     * so recording the outcome days later updates the entry rather than stacking a second one on
     * top of it.
     */
    case Contacted = 'contacted';

    // What the team did with a signal (Signals, S4). One case, not two: converting a signal and
    // linking it to a company the team already had are the same act with a different outcome, which
    // the `attached` property carries — exactly how `Imported` carries `merged`.
    case SignalConverted = 'signal_converted';

    /**
     * Something happened to this company, and Alima noticed without being asked (Signals S10).
     *
     * Distinct from {@see self::SignalConverted}, and the difference is the phase's whole point:
     * that one says *this lead exists because of an event*, this one says *an event happened to a
     * lead you already had*. One is provenance; the other is news. A lead's timeline becomes a
     * record of what has happened to that company, which is what makes the page worth opening in
     * the morning.
     */
    case SignalMatched = 'signal_matched';

    public function label(): string
    {
        return match ($this) {
            self::Discovered => 'Discovered',
            self::Enriched => 'Enriched',
            self::Scored => 'Graded',
            self::StageChanged => 'Moved',
            self::NoteAdded => 'Note added',
            self::ContactAdded => 'Contact added',
            self::TaskCreated => 'Task added',
            self::TaskCompleted => 'Task done',
            self::Assigned => 'Assigned',
            self::Unassigned => 'Unassigned',
            self::TagsChanged => 'Tags changed',
            self::Imported => 'Imported',
            self::Created => 'Added by hand',
            self::Contacted => 'Contacted',
            self::SignalConverted => 'From a signal',
            self::SignalMatched => 'Something happened',
        };
    }

    /**
     * Whether the platform produced this entry on its own (a queued job) rather than a member doing
     * it. The timeline uses it to attribute the entry to Alima instead of a person.
     */
    public function isAutomatic(): bool
    {
        return match ($this) {
            // `SignalMatched` is the clearest case there is: a scheduled watch found it and
            // attributing it to whoever happened to be logged in would be a small lie.
            self::Discovered, self::Enriched, self::Scored, self::SignalMatched => true,
            self::StageChanged, self::NoteAdded, self::ContactAdded, self::TaskCreated, self::TaskCompleted,
            self::Assigned, self::Unassigned, self::TagsChanged, self::Imported,
            self::Created, self::Contacted, self::SignalConverted => false,
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
