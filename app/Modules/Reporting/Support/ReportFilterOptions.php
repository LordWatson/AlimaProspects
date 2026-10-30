<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use App\Modules\Reporting\Enums\ReportKey;
use App\Modules\Reporting\Enums\ReportRange;

/**
 * The option lists the dashboard's filter and export controls are built from.
 *
 * Derived from the enums rather than typed out in the Vue components, so a new range or a new
 * report appears in the UI by existing — the alternative is a control that silently offers nine of
 * ten reports and nobody notices which one is missing.
 *
 * Labels are translation keys; the screen resolves them (ADR 0007).
 */
final class ReportFilterOptions
{
    /**
     * @return array{ranges: list<array{value: string, label: string}>, reports: list<array{value: string, label: string}>}
     */
    public static function all(): array
    {
        return [
            'ranges' => array_map(
                static fn (ReportRange $range): array => [
                    'value' => $range->value,
                    'label' => $range->label(),
                ],
                ReportRange::presets(),
            ),
            'reports' => array_map(
                static fn (ReportKey $key): array => [
                    'value' => $key->value,
                    'label' => $key->label(),
                ],
                ReportKey::cases(),
            ),
        ];
    }
}
