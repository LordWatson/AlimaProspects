<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Support\Str;

/**
 * Cell-level hardening shared by every CSV the platform writes (leads, reports).
 *
 * It lives in Core because the risk is the same wherever it is written: the data in a cell did not
 * come from us. A company name can arrive from a web-search result, a CSV a team was sent or an
 * AI summary, and the file is then opened in Excel by an Owner — so the one thing every exporter
 * must do is stop a spreadsheet treating a cell as a formula.
 */
final class CsvCell
{
    /**
     * Stop a spreadsheet treating a cell as a formula.
     *
     * A lead called `=HYPERLINK("http://evil.test","Click")` is a cell somebody will open in
     * Excel. Prefixing an apostrophe makes it text; Excel and LibreOffice both hide the prefix,
     * and `LeadCsvImporter` strips it on the way back in, so a round trip is unaffected.
     */
    public static function neutralise(string $cell): string
    {
        return Str::startsWith($cell, ['=', '+', '-', '@', "\t", "\r"])
            ? "'".$cell
            : $cell;
    }
}
