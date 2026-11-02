<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use App\Modules\Admin\Enums\PlatformReport;
use App\Modules\Reporting\Data\ReportColumn;
use App\Modules\Reporting\Data\ReportRow;
use App\Modules\Reporting\Data\ReportTable;
use Spatie\LaravelData\Data;

/**
 * One platform report, as a table (Phase 19c).
 *
 * The same shape as Reporting's {@see ReportTable} — same `ReportColumn`, same `ReportRow`, so the
 * same Vue component renders it and the same CSV writer writes it — with two differences, both
 * deliberate:
 *
 * 1. **Its key is a {@see PlatformReport}, not a `ReportKey`.** A platform figure must not be
 *    reachable through a tenant's enum; see that enum's note.
 * 2. **It carries `definitions`.** This is the phase's own risk control made concrete: a platform
 *    aggregate that quietly answers a tenant-shaped question has no customer to complain, so every
 *    figure's counting rule travels with the figure, onto the screen and into the CSV caption.
 *    `ReportTable` has one `caption` because a tenant report answers one question; several of
 *    these carry four or five measures whose rules differ.
 *
 * Property names are snake_case: read straight off the Inertia page props.
 */
final class PlatformReportTable extends Data
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<ReportRow>  $rows
     * @param  list<string>  $definitions  one per measure, in column order where they correspond
     */
    public function __construct(
        public readonly PlatformReport $key,
        public readonly string $title,
        public readonly string $caption,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $definitions = [],
        public readonly string $empty_message = 'Nothing rolled up for this window yet.',
    ) {}

    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<ReportRow>  $rows
     * @param  list<string>  $definitions
     */
    public static function of(
        PlatformReport $key,
        array $columns,
        array $rows,
        array $definitions = [],
        ?string $emptyMessage = null,
    ): self {
        return new self(
            key: $key,
            title: $key->label(),
            caption: $key->caption(),
            columns: $columns,
            rows: $rows,
            definitions: $definitions,
            empty_message: $emptyMessage ?? 'Nothing rolled up for this window yet.',
        );
    }
}
