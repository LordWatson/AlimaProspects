<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Support;

use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadsWorkspace\Enums\LeadSort;
use App\Modules\Scoring\Enums\ScoreGrade;

/**
 * The option lists the leads list's filter bar renders (Phase 10) — built from the enums, so adding
 * a grade, a provider or an ordering shows up in the UI without anyone editing a template. Labels
 * are translation keys; the client passes them through `t()`.
 */
final class LeadFilterOptions
{
    /**
     * @return array{
     *     grades: list<array{value: string, label: string}>,
     *     sources: list<array{value: string, label: string}>,
     *     enrichment_statuses: list<array{value: string, label: string}>,
     *     sorts: list<array{value: string, label: string}>,
     * }
     */
    public static function all(): array
    {
        return [
            'grades' => self::map(ScoreGrade::cases()),
            'sources' => self::map(LeadSource::cases()),
            'enrichment_statuses' => self::map(EnrichmentStatus::cases()),
            'sorts' => self::map(LeadSort::cases()),
        ];
    }

    /**
     * @param  array<int, ScoreGrade|LeadSource|EnrichmentStatus|LeadSort>  $cases
     * @return list<array{value: string, label: string}>
     */
    private static function map(array $cases): array
    {
        return array_values(array_map(
            static fn (ScoreGrade|LeadSource|EnrichmentStatus|LeadSort $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        ));
    }
}
