<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Data;

use Spatie\LaravelData\Data;

/**
 * What a CSV import did (Phase 10b). Reported back to the member rather than just "done", because
 * "40 imported, 3 merged into leads you already had, 1 row skipped" is the only answer that tells
 * them whether to go looking for something.
 */
final class LeadImportResult extends Data
{
    /** A row with nothing in the column mapped to the company name. */
    public const string NO_COMPANY_NAME = 'no_company_name';

    /** A row the database refused — most likely a cell longer than its column. */
    public const string COULD_NOT_SAVE = 'could_not_save';

    /**
     * @param  list<array{row: int, reason: self::NO_COMPANY_NAME|self::COULD_NOT_SAVE}>  $skipped
     *                                                                                              Row numbers as the member sees them in their spreadsheet (header = 1).
     */
    public function __construct(
        public readonly int $created = 0,
        public readonly int $merged = 0,
        public readonly array $skipped = [],
        public readonly bool $truncated = false,
    ) {}

    public function total(): int
    {
        return $this->created + $this->merged;
    }
}
