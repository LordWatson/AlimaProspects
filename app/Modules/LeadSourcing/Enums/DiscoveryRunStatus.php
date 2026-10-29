<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Enums;

use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use App\Modules\LeadSourcing\Models\DiscoveryRun;

/**
 * The lifecycle of a {@see DiscoveryRun}. A run is created {@see self::Pending} the moment it is
 * queued, flips to {@see self::Running} while {@see DiscoverLeadsJob}
 * talks to the provider, then settles {@see self::Completed} (leads written) or {@see self::Failed}
 * (the provider errored or the run could not finish).
 *
 * Values are stable storage keys; {@see self::label()} is the display name. No magic strings.
 */
enum DiscoveryRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
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
