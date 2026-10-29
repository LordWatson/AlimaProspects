<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

use App\Modules\Signals\Models\SignalWatch;

/**
 * How often a {@see SignalWatch} runs. The hourly scheduler
 * (`signals:dispatch-due`, S3) queues a run when a watch's `last_run_at` is older than
 * {@see self::staleAfterHours()}: Daily after 24 hours, Weekly after 7 days. Keeping the interval on
 * the enum avoids magic numbers in the scheduler.
 *
 * Values are stable storage keys; {@see self::label()} is the display name. No magic strings.
 */
enum SignalWatchCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
        };
    }

    /**
     * How many hours must pass since the last run before this cadence is due again.
     */
    public function staleAfterHours(): int
    {
        return match ($this) {
            self::Daily => 24,
            self::Weekly => 24 * 7,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $cadence): string => $cadence->value, self::cases());
    }
}
