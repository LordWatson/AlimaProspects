<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Enums;

use App\Modules\LeadsWorkspace\Actions\BulkUpdateLeadsAction;

/**
 * What a bulk action does to the selected leads (Phase 10b). An enum, so the endpoint can only ever
 * be asked for something {@see BulkUpdateLeadsAction} knows how to do.
 *
 * Two more are named in the plan and deliberately absent: **suppress** needs the suppression list
 * that arrives with Compliance & Privacy (Phase 15), and **add to outreach** needs the Outreach
 * module (Phase 11). Adding a case here that silently did nothing would be worse than not offering
 * it, so they land with the modules that give them meaning.
 */
enum BulkLeadAction: string
{
    case AddTags = 'add_tags';
    case RemoveTags = 'remove_tags';
    case Assign = 'assign';
    case Unassign = 'unassign';
    case MoveStage = 'move_stage';

    public function label(): string
    {
        return match ($this) {
            self::AddTags => 'Add tags',
            self::RemoveTags => 'Remove tags',
            self::Assign => 'Assign to',
            self::Unassign => 'Remove assignee',
            self::MoveStage => 'Move to stage',
        };
    }

    /**
     * Which extra field the action needs alongside the lead ids, so the request can validate only
     * what this action actually uses. Every action needs exactly one.
     */
    public function requires(): string
    {
        return match ($this) {
            self::AddTags, self::RemoveTags => 'tags',
            self::Assign, self::Unassign => 'user_id',
            self::MoveStage => 'pipeline_stage_id',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $action): string => $action->value, self::cases());
    }
}
