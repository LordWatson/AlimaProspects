<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use Spatie\LaravelData\Data;

/**
 * One row of a {@see ReportTable}.
 *
 * Cells are keyed by column key rather than positional. Positional rows are shorter to write and
 * one re-ordered column away from a report that silently lies — a reply rate printed under
 * "Attempts" is worse than a missing column, because nobody notices it.
 */
final class ReportRow extends Data
{
    /**
     * @param  array<string, string|int|float|null>  $cells
     */
    public function __construct(
        public readonly string $key,
        public readonly array $cells,
    ) {}
}
