<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Enums;

use App\Modules\LeadsWorkspace\Models\PipelineStage;

/**
 * Whether reaching a {@see PipelineStage} ends the lead's journey, and how. Null on the stages in
 * between. Reporting (Phase 13) reads this to work out conversion rather than guessing from stage
 * names — a team that renames "Won" to "Signed" shouldn't break its own funnel.
 *
 * Values are stable storage keys; {@see self::label()} is a translation key.
 */
enum StageOutcome: string
{
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $outcome): string => $outcome->value, self::cases());
    }
}
