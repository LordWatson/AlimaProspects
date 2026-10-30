<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use Spatie\LaravelData\Data;

/**
 * One bucket on the dashboard's sourced-vs-qualified trend line: a week, how many leads arrived in
 * it, and how many of those the scorer has graded.
 *
 * Property names are snake_case because this shape is read straight off the Inertia page props by
 * `resources/js/types/reporting.ts` — it keeps the wire format the same as the API Resources the
 * rest of the frontend consumes.
 */
final class TrendPoint extends Data
{
    public function __construct(
        /** Short axis label in the team's timezone, e.g. "6 Oct". */
        public readonly string $label,
        /** ISO-8601 date of the Monday that opens the bucket — the tooltip's full date. */
        public readonly string $starts_on,
        public readonly int $sourced,
        public readonly int $qualified,
    ) {}
}
