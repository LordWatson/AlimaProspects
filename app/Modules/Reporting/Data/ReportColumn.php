<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use App\Modules\Reporting\Enums\ColumnFormat;
use Spatie\LaravelData\Data;

/**
 * One column of a {@see ReportTable}.
 *
 * `label` is always a translation key. `translated` says whether the **cells** are too, which is
 * the distinction that keeps a report honest in Arabic: a channel or a role is one of Alima's own
 * words and must be translated, whereas a member's name, an ICP's name or a prompt key is data and
 * must be printed exactly as the team wrote it. Translating the second kind would mangle it; not
 * translating the first would ship English into an Arabic report.
 */
final class ReportColumn extends Data
{
    public function __construct(
        public readonly string $key,
        /** Translation key, not a finished string. */
        public readonly string $label,
        public readonly ColumnFormat $format = ColumnFormat::Text,
        /** True when every cell in this column is a translation key rather than data. */
        public readonly bool $translated = false,
        /** ISO currency code — set only when `format` is money. */
        public readonly ?string $currency = null,
    ) {}

    public static function count(string $key, string $label): self
    {
        return new self($key, $label, ColumnFormat::Count);
    }

    public static function percent(string $key, string $label): self
    {
        return new self($key, $label, ColumnFormat::Percent);
    }

    public static function money(string $key, string $label, string $currency): self
    {
        return new self($key, $label, ColumnFormat::Money, currency: $currency);
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, ColumnFormat::Date);
    }

    /** A column of Alima's own words — an enum label, a status. */
    public static function translated(string $key, string $label): self
    {
        return new self($key, $label, ColumnFormat::Text, translated: true);
    }
}
