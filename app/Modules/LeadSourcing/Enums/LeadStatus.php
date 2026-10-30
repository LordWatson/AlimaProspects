<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Enums;

use App\Modules\LeadSourcing\Models\Lead;

/**
 * The pipeline stage of a {@see Lead} as it moves through the platform. Lead Sourcing only ever
 * creates a lead {@see self::Discovered}; later phases advance it — Enrichment (Phase 8) sets
 * {@see self::Enriched}, Scoring (Phase 9) sets {@see self::Scored}. Kept here so the column has a
 * typed home from the first write; downstream modules reuse the same enum.
 *
 * Values are stable storage keys; {@see self::label()} is the display name. No magic strings.
 */
enum LeadStatus: string
{
    case Discovered = 'discovered';
    case Enriched = 'enriched';
    case Scored = 'scored';

    public function label(): string
    {
        return match ($this) {
            self::Discovered => 'Discovered',
            self::Enriched => 'Enriched',
            self::Scored => 'Scored',
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
