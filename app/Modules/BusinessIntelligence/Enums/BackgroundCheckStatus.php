<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Enums;

use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;

/**
 * The lifecycle of a {@see BusinessIntelReport}. A report
 * is created {@see self::Pending} the moment a check is requested, flips to {@see self::Ready} when
 * the AI run completes and is awaiting the team's review, {@see self::Accepted} once the team merges
 * it into their Business Profile, or {@see self::Failed} if the run could not complete.
 *
 * Values are stable storage keys; {@see self::label()} is the display name. No magic strings.
 */
enum BackgroundCheckStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Accepted = 'accepted';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In progress',
            self::Ready => 'Ready to review',
            self::Accepted => 'Accepted',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether the run has finished (successfully or not) and no longer broadcasts progress.
     */
    public function isTerminal(): bool
    {
        return $this === self::Ready || $this === self::Accepted || $this === self::Failed;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
