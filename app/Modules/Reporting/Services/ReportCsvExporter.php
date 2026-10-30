<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Core\Support\CsvCell;
use App\Modules\Reporting\Data\ReportColumn;
use App\Modules\Reporting\Data\ReportRow;
use App\Modules\Reporting\Data\ReportTable;
use App\Modules\Reporting\Enums\ColumnFormat;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use Throwable;

/**
 * Writes a {@see ReportTable} out as CSV (Phase 13).
 *
 * One writer for every report, because every report is a table — so adding a chart does not add an
 * exporter, and a download can never show different numbers from the screen it came from.
 *
 * ## What a spreadsheet wants
 *
 * Not what a screen wants. A cell is written as a **bare number** wherever it is one, so the file
 * can be summed and charted: money becomes major units with two decimals and the currency goes in
 * the header (`Cost (GBP)`), a rate becomes a number out of a hundred with the unit in the header
 * (`Reply rate (%)`), and a date becomes ISO-8601 — unambiguous in every locale's spreadsheet.
 *
 * Words are translated through `__()`, so an Arabic session downloads an Arabic report: headers
 * always, and cells only in the columns that hold Alima's own words (see {@see ReportColumn}).
 * A member's name or a prompt key is data and is written exactly as stored.
 *
 * An **empty cell, not a zero**, wherever the value is null. The funnel's unavailable steps and a
 * rate over nothing are not zeroes, and a spreadsheet that says `0%` where we meant "we did not
 * ask anyone" is the one lie this whole module is built to avoid.
 */
final class ReportCsvExporter
{
    /**
     * Yields the header row followed by one row per record, as arrays ready for `fputcsv`.
     *
     * A generator even though a report is small: the response streams, so nothing has to hold the
     * whole file, and the shape matches the leads exporter a reader may already know.
     *
     * @return Generator<int, list<string>>
     */
    public function rows(ReportTable $table): Generator
    {
        yield array_map(fn (ReportColumn $column): string => $this->header($column), $table->columns);

        foreach ($table->rows as $row) {
            yield $this->row($table, $row);
        }
    }

    /**
     * The download's filename. Dated, because a report without its window is a number without a
     * question — and two exports a month apart must not look like the same file.
     */
    public function filename(ReportTable $table, ?string $from, ?string $to): string
    {
        $window = match (true) {
            $from !== null && $to !== null => $from.'-to-'.$to,
            $to !== null => $to,
            default => 'all-time',
        };

        return 'alima-'.$table->key->slug().'-'.$window.'.csv';
    }

    private function header(ReportColumn $column): string
    {
        $label = __($column->label);
        $label = is_string($label) ? $label : $column->label;

        return match ($column->format) {
            // The unit belongs in the header so the cells stay numbers. A column headed "Cost"
            // whose cells read "£1.20" cannot be summed in the one application this file is for.
            ColumnFormat::Money => $column->currency === null
                ? $label
                : $label.' ('.$column->currency.')',
            ColumnFormat::Percent => $label.' (%)',
            default => $label,
        };
    }

    /**
     * @return list<string>
     */
    private function row(ReportTable $table, ReportRow $row): array
    {
        $cells = [];

        foreach ($table->columns as $column) {
            $cells[] = $this->harden($column, $this->cell($column, $row->cells[$column->key] ?? null));
        }

        return $cells;
    }

    /**
     * Neutralise a cell a spreadsheet might execute — but **only where the cell holds words**.
     *
     * {@see CsvCell::neutralise()} prefixes an apostrophe to anything starting `=`, `+`, `-` or
     * `@`, which is right for a company name and wrong for `-3`: a negative KPI delta would be
     * written as `'-3` and land in the spreadsheet as text, so the column the member wanted to sum
     * could not be summed. Numeric cells are produced here by `number_format`/`(int)` and cannot
     * carry a formula, so they are written bare.
     */
    private function harden(ReportColumn $column, string $cell): string
    {
        return $column->format->isNumeric() ? $cell : CsvCell::neutralise($cell);
    }

    private function cell(ReportColumn $column, string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($column->format) {
            ColumnFormat::Count => (string) (int) $value,
            // Integer minor units in, major units out: the number a spreadsheet can add up.
            //
            // The /100 assumes a two-decimal currency, which both MVP markets use (GBP, AED). A
            // market with a different exponent — JOD at three, JPY at nought — would need the
            // divisor read off the currency rather than assumed here.
            ColumnFormat::Money => number_format((int) $value / 100, 2, '.', ''),
            // A fraction in, a number out of a hundred out — paired with the `(%)` in the header.
            ColumnFormat::Percent => number_format((float) $value * 100, 1, '.', ''),
            ColumnFormat::Date => $this->date((string) $value),
            ColumnFormat::Text => $this->text($column, (string) $value),
        };
    }

    /**
     * Alima's own words get translated; the team's data does not (see {@see ReportColumn}).
     */
    private function text(ReportColumn $column, string $value): string
    {
        if (! $column->translated) {
            return $value;
        }

        $translated = __($value);

        return is_string($translated) ? $translated : $value;
    }

    /**
     * ISO-8601, in UTC when the value carries a time and as a plain date when it does not — a
     * bucket's `starts_on` is a date, and padding it with a midnight it never had would invite a
     * reader to think the window opened at a particular hour.
     */
    private function date(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        try {
            return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
        } catch (Throwable) {
            return $value;
        }
    }
}
