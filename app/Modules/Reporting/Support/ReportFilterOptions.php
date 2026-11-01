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
            'ranges' => self::ranges(),
            'reports' => array_map(
                static fn (ReportKey $key): array => [
                    'value' => $key->value,
                    'label' => $key->label(),
                ],
                ReportKey::cases(),
            ),
        ];
    }

    /**
     * The window presets on their own.
     *
     * Split out for the Admin panel's platform reporting (Phase 19c), which asks the same question
     * about windows and an entirely different one about reports: handing it `all()` would have put
     * the **tenant** report list on an operator's screen, where choosing one would mean nothing.
     * One preset list, two report lists — which is the shape of the two modules' relationship.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function ranges(): array
    {
        return array_map(
            static fn (ReportRange $range): array => [
                'value' => $range->value,
                'label' => $range->label(),
            ],
            ReportRange::presets(),
        );
    }
}
