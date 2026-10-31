<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use BackedEnum;

/**
 * An equality filter on a registry resource's index (Phase 19a).
 *
 * Equality only, over a declared option list. That is a deliberate ceiling: an operator's filters
 * become a `where` on a cross-tenant query with no `team_id` in it, and an open-ended filter
 * grammar would be both an index you cannot plan for and a slow query nobody wrote. Ranges and
 * windows belong to Phase 19c's reporting, where they are aggregated rather than scanned.
 */
final readonly class ResourceFilter
{
    /**
     * @param  array<string, string>  $options  value => label.
     * @param  bool  $isBoolean  Whether the option values map onto a boolean column. Declared
     *                           rather than inferred from the option list, so the query layer does
     *                           not have to recognise one array shape as special.
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $options,
        public bool $isBoolean = false,
    ) {}

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public static function enum(string $name, string $label, string $enum): self
    {
        $options = [];

        foreach ($enum::cases() as $case) {
            $value = (string) $case->value;
            $options[$value] = method_exists($case, 'label') ? (string) $case->label() : $value;
        }

        return new self($name, $label, $options);
    }

    /**
     * `yes`/`no` rather than `1`/`0`, for two reasons: PHP silently casts numeric string array keys
     * to integers, which breaks the `array<string, string>` the options are declared as; and
     * `?filters[is_active]=yes` is a query string an operator can read in a ticket.
     */
    public static function boolean(string $name, string $label): self
    {
        return new self($name, $label, ['yes' => 'Yes', 'no' => 'No'], isBoolean: true);
    }

    public function accepts(string $value): bool
    {
        return array_key_exists($value, $this->options);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'options' => $this->options,
        ];
    }
}
