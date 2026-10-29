<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Enums;

use App\Modules\LeadSourcing\Enums\LeadStatus;
use App\Modules\LeadSourcing\Models\Lead;

/**
 * Where a {@see Lead} is in the enrichment pipeline (Phase 8). A lead is {@see self::Pending} when
 * Lead Sourcing creates it, {@see self::Enriching} while the job works it, and settles
 * {@see self::Enriched} or {@see self::Failed}. Kept separate from {@see LeadStatus}
 * so sourcing/scoring stage and data-quality state are tracked independently.
 *
 * Values are stable storage keys; {@see self::label()} is the display name. No magic strings.
 */
enum EnrichmentStatus: string
{
    case Pending = 'pending';
    case Enriching = 'enriching';
    case Enriched = 'enriched';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Enriching => 'Enriching',
            self::Enriched => 'Enriched',
            self::Failed => 'Failed',
        };
    }

    /**
     * A settled state the enrichment job won't re-run on an idempotent retry.
     */
    public function isTerminal(): bool
    {
        return $this === self::Enriched || $this === self::Failed;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
