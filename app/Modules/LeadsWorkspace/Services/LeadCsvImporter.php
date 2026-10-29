<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Services;

use App\Modules\LeadsWorkspace\Exceptions\LeadsWorkspaceException;
use Generator;
use Illuminate\Support\Str;
use SplFileObject;

/**
 * Reads a CSV upload into rows keyed by the member's column mapping (Phase 10b).
 *
 * Deliberately conservative about what a "CSV" is, because the file comes from whatever the team
 * happened to export from:
 *
 * - **Encoding.** A UTF-8 BOM (which Excel writes) is stripped, and anything that isn't valid UTF-8
 *   is converted from Windows-1252 — the encoding Excel on Windows actually produces when it says
 *   "CSV". Without this, an accented company name arrives as mojibake and is stored that way.
 * - **Delimiter.** Comma, semicolon or tab, sniffed from the header line. A European Excel writes
 *   semicolons, and rejecting those files would be rejecting half the real ones.
 * - **Ragged rows.** A row with fewer cells than the header is padded rather than dropped: the last
 *   columns being empty is normal, and losing the row would lose the lead.
 * - **Bounded.** `leads-workspace.csv.max_import_rows` caps how much one upload reads, and the
 *   reader says when it stopped early rather than pretending it finished.
 */
final class LeadCsvImporter
{
    /** The delimiters worth sniffing for, most likely first. */
    private const array DELIMITERS = [',', ';', "\t"];

    /**
     * The header row, normalised, in file order.
     *
     * @return list<string>
     */
    public function headers(string $path): array
    {
        $file = $this->open($path);
        $delimiter = $this->sniff($path);

        $file->setCsvControl($delimiter);
        $file->rewind();

        /** @var array<int, string|null>|false $row */
        $row = $file->current();

        if (! is_array($row)) {
            throw LeadsWorkspaceException::unreadableCsv();
        }

        $headers = [];

        foreach ($row as $index => $cell) {
            $name = $this->text((string) ($cell ?? ''));
            // A nameless column still has to be addressable by position, or the mapping would
            // silently shift everything after it.
            $headers[] = $name === '' ? 'column_'.($index + 1) : $name;
        }

        if ($headers === []) {
            throw LeadsWorkspaceException::unreadableCsv();
        }

        return $headers;
    }

    /**
     * Yields `[rowNumber, values]` for each data row, where `values` is keyed by the mapping's
     * target field. `rowNumber` is what the member sees in their spreadsheet (the header is row 1),
     * so a "row 14 was skipped" message points at the right line.
     *
     * @param  array<string, string>  $mapping  target field => CSV column name
     * @return Generator<int, array{int, array<string, string>}>
     */
    public function rows(string $path, array $mapping): Generator
    {
        $headers = $this->headers($path);
        $positions = $this->positions($headers, $mapping);

        if ($positions === []) {
            throw LeadsWorkspaceException::missingCompanyColumn();
        }

        $file = $this->open($path);
        $file->setCsvControl($this->sniff($path));

        $max = max(1, (int) config('leads-workspace.csv.max_import_rows', 2_000));
        $width = count($headers);
        $rowNumber = 0;
        $yielded = 0;

        foreach ($file as $row) {
            $rowNumber++;

            if ($rowNumber === 1 || ! is_array($row)) {
                continue; // the header, or SplFileObject's trailing null row
            }

            // A line that is entirely empty is the blank line at the end of most exports.
            if ($row === [null] || $row === ['']) {
                continue;
            }

            if ($yielded >= $max) {
                return;
            }

            $values = [];

            foreach ($positions as $field => $index) {
                $values[$field] = $index < $width
                    ? $this->text((string) ($row[$index] ?? ''))
                    : '';
            }

            $yielded++;

            yield [$rowNumber, $values];
        }
    }

    /**
     * Whether the file holds more data rows than one import will read.
     */
    public function exceedsLimit(string $path): bool
    {
        $max = max(1, (int) config('leads-workspace.csv.max_import_rows', 2_000));

        $file = $this->open($path);
        $file->setCsvControl($this->sniff($path));
        $file->seek(PHP_INT_MAX);

        // `key()` after seeking to the end is the last line's index; minus the header, and minus
        // the blank final line most exports end with.
        return max(0, $file->key() - 1) > $max;
    }

    /**
     * The CSV column index behind each mapped field, dropping fields mapped to a column the file
     * doesn't have. Returns `[]` when nothing maps to `company_name`, which is the one field a lead
     * can't be created without.
     *
     * @param  list<string>  $headers
     * @param  array<string, string>  $mapping
     * @return array<string, int>
     */
    private function positions(array $headers, array $mapping): array
    {
        $byName = [];

        foreach ($headers as $index => $header) {
            $byName[Str::lower($header)] = $index;
        }

        $positions = [];

        foreach ($mapping as $field => $column) {
            $key = Str::lower($this->text($column));

            if ($key !== '' && array_key_exists($key, $byName)) {
                $positions[$field] = $byName[$key];
            }
        }

        return array_key_exists('company_name', $positions) ? $positions : [];
    }

    private function open(string $path): SplFileObject
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw LeadsWorkspaceException::unreadableCsv();
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        return $file;
    }

    /**
     * Guess the delimiter from the header line: whichever candidate appears most often outside
     * quotes. Falls back to a comma, which is what the name promises.
     */
    private function sniff(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw LeadsWorkspaceException::unreadableCsv();
        }

        $line = fgets($handle, 8_192);
        fclose($handle);

        if ($line === false) {
            throw LeadsWorkspaceException::unreadableCsv();
        }

        $line = $this->decode($line);
        $best = ',';
        $bestCount = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = substr_count($line, $delimiter);

            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * Trim a cell and make sure it is UTF-8, whatever the spreadsheet wrote.
     *
     * A leading apostrophe is dropped: that is what {@see LeadCsvExporter} adds to stop a
     * spreadsheet reading a cell as a formula, so an Alima export has to import back as the value
     * it started as rather than as `'=Acme`.
     */
    private function text(string $value): string
    {
        $value = Str::squish($this->decode($value));

        return Str::startsWith($value, "'") && Str::startsWith(Str::substr($value, 1), ['=', '+', '-', '@'])
            ? Str::substr($value, 1)
            : $value;
    }

    private function decode(string $value): string
    {
        // Excel prefixes a UTF-8 file with a BOM; left in place it becomes part of the first header
        // name and nothing maps to it.
        $value = Str::replaceStart("\u{FEFF}", '', $value);

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
