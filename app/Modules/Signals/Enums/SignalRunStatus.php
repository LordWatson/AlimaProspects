<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

use App\Modules\Signals\Models\SignalRun;

/**
 * The lifecycle of a {@see SignalRun}. Mirrors `DiscoveryRunStatus`: a run is created
 * {@see self::Queued} the moment it is scheduled, flips to {@see self::Running} while the job talks
 * to Serper and the AI, then settles {@see self::Completed} (signals persisted) or {@see self::Failed}
 * (the provider errored, credit ran out, or the run could not finish).
 *
 * Values are stable storage keys; {@see self::label()} is the display name. No magic strings.
 */
enum SignalRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'In progress',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether the run has finished (successfully or not) and no longer broadcasts progress.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }

    /**
     * Whether the run is still queued or working (and may still broadcast progress).
     */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
