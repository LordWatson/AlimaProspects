<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Support;

use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadsWorkspace\Enums\LeadSort;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Support\Str;

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
     *     evidence_kinds: list<array{value: string, label: string}>,
     *     evidence_sources: list<array{value: string, label: string}>,
     *     confidence_bands: array{high: int, medium: int},
     * }
     */
    public static function all(): array
    {
        return [
            'grades' => self::map(ScoreGrade::cases()),
            'sources' => self::map(LeadSource::cases()),
            'enrichment_statuses' => self::map(EnrichmentStatus::cases()),
            'sorts' => self::map(LeadSort::cases()),
            // --- Why now (PL1) ---
            'evidence_kinds' => self::map(EvidenceKind::cases()),
            // Built from the **config** rather than from an enum, because an evidence source is
            // data here (ADR 0018 Section 1). `default` is the fallback weight, not a source, so it
            // is not an option anybody could filter by.
            'evidence_sources' => self::sources(),
            // So the pill's wording matches the thresholds the server actually used.
            'confidence_bands' => [
                'high' => (int) config('evidence.bands.high', 75),
                'medium' => (int) config('evidence.bands.medium', 50),
            ],
        ];
    }

    /**
     * The evidence sources a team can filter by: every key in `evidence.reliability` except the
     * fallback. Labelled from `LeadSource` where the key is one of ours, else from the key itself —
     * a source this build does not know is still filterable, which is what keeps a saved view
     * working across the phase that adds one.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function sources(): array
    {
        /** @var array<string, int> $weights */
        $weights = (array) config('evidence.reliability', []);

        $keys = array_values(array_filter(
            array_keys($weights),
            static fn (string $key): bool => $key !== 'default',
        ));

        sort($keys);

        return array_map(static fn (string $key): array => [
            'value' => $key,
            'label' => LeadSource::tryFrom($key)?->label() ?? Str::headline($key),
        ], $keys);
    }

    /**
     * @param  array<int, ScoreGrade|LeadSource|EnrichmentStatus|LeadSort|EvidenceKind>  $cases
     * @return list<array{value: string, label: string}>
     */
    private static function map(array $cases): array
    {
        return array_values(array_map(
            static fn (ScoreGrade|LeadSource|EnrichmentStatus|LeadSort|EvidenceKind $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        ));
    }
}
