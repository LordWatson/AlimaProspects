<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Enums;

use App\Modules\LeadsWorkspace\Models\LeadTask;

/**
 * Where a {@see LeadTask} stands. Deliberately two states and no "in progress": a CRM-lite task is
 * either still waiting for someone or it isn't, and a third state only invites the team to leave
 * things in it.
 *
 * Values are stable storage keys; {@see self::label()} is a translation key.
 */
enum LeadTaskStatus: string
{
    case Open = 'open';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'To do',
            self::Done => 'Done',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
