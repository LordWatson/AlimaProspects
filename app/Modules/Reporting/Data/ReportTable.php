<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use App\Modules\Reporting\Enums\ReportKey;
use App\Modules\Reporting\Services\ReportCsvExporter;
use App\Modules\Reporting\Services\ReportTables;
use Spatie\LaravelData\Data;

/**
 * Any report, as a table.
 *
 * This is the shape that makes "**CSV export of any report**" a single piece of code rather than
 * one writer per chart: {@see ReportTables} projects every {@see ReportKey} into one of these, the
 * screen renders it, and {@see ReportCsvExporter} writes it. A chart and its download therefore
 * cannot drift apart, because they are the same object.
 *
 * Property names are snake_case: this shape is read straight off the Inertia page props by
 * `resources/js/types/reporting.ts`.
 */
final class ReportTable extends Data
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<ReportRow>  $rows
     */
    public function __construct(
        public readonly ReportKey $key,
        /** Translation key, not a finished string. */
        public readonly string $title,
        /** Translation key, not a finished string. */
        public readonly string $caption,
        public readonly array $columns,
        public readonly array $rows,
        /** Translation key shown instead of an empty grid. */
        public readonly string $empty_message = 'Nothing to report for this period.',
    ) {}

    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<ReportRow>  $rows
     */
    public static function of(
        ReportKey $key,
        array $columns,
        array $rows,
        ?string $emptyMessage = null,
    ): self {
        return new self(
            key: $key,
            title: $key->label(),
            caption: $key->caption(),
            columns: $columns,
            rows: $rows,
            empty_message: $emptyMessage ?? 'Nothing to report for this period.',
        );
    }
}
