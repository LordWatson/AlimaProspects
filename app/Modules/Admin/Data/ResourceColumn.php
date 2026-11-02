<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use App\Modules\Reporting\Enums\ColumnFormat;

/**
 * One column of a registry resource's index and show pages (Phase 19a).
 *
 * The format is Reporting's {@see ColumnFormat}, reused rather than reinvented: the admin panel
 * needs exactly the same five kinds of cell, and a second enum would mean a second formatting
 * layer on the client drifting from the first. The value crosses the wire raw for the same reason
 * it does in Reporting — only the client knows the viewer's locale, only the CSV writer knows a
 * spreadsheet wants `12.40`.
 *
 * `cost_micros` columns are declared as {@see ColumnFormat::Count}, not `Money`: micro-units are
 * not minor units, and labelling them as money would show a figure a thousand times too small with
 * a currency symbol on it. The label says "micros" instead, which is honest and unambiguous.
 */
final readonly class ResourceColumn
{
    /**
     * @param  string  $name  The attribute to read. Dot notation reaches an eager-loaded relation
     *                        (`team.name`), which is why a resource declaring one must also declare
     *                        the relation in `with()`.
     * @param  string|null  $currencyField  For {@see ColumnFormat::Money} only: the sibling
     *                                      attribute holding the ISO currency code.
     */
    public function __construct(
        public string $name,
        public string $label,
        public ColumnFormat $format = ColumnFormat::Text,
        public ?string $currencyField = null,
    ) {}

    public static function text(string $name, string $label): self
    {
        return new self($name, $label);
    }

    public static function count(string $name, string $label): self
    {
        return new self($name, $label, ColumnFormat::Count);
    }

    public static function money(string $name, string $label, string $currencyField): self
    {
        return new self($name, $label, ColumnFormat::Money, $currencyField);
    }

    public static function percent(string $name, string $label): self
    {
        return new self($name, $label, ColumnFormat::Percent);
    }

    public static function date(string $name, string $label): self
    {
        return new self($name, $label, ColumnFormat::Date);
    }

    /**
     * Whether this column reads a relation rather than a column on the resource's own table — the
     * distinction the contract test needs, since `Schema::hasColumn` cannot check `team.name`.
     */
    public function isRelational(): bool
    {
        return str_contains($this->name, '.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'format' => $this->format->value,
            'numeric' => $this->format->isNumeric(),
            'currency_field' => $this->currencyField,
        ];
    }
}
