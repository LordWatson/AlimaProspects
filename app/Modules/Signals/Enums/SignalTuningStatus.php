<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

use App\Modules\Signals\Services\SignalTuningAdvisor;

/**
 * Where a tuning suggestion stands (S12).
 *
 * **A declined suggestion is kept, not deleted**, and that is the reason this is a status rather
 * than a row that disappears: {@see SignalTuningAdvisor} must not propose next week what the team
 * turned down this week. That is the difference between a suggestion and a nag, and the only way
 * to know is to remember having asked.
 */
enum SignalTuningStatus: string
{
    /** Waiting for the team. The only status the settings panel shows. */
    case Proposed = 'proposed';

    /** The team applied it. The row stays as the record of why a definition changed. */
    case Accepted = 'accepted';

    /** The team said no. Kept so it is never proposed again. */
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Suggested',
            self::Accepted => 'Applied',
            self::Declined => 'Dismissed',
        };
    }

    /** Whether the team has answered this one. */
    public function isSettled(): bool
    {
        return $this !== self::Proposed;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
