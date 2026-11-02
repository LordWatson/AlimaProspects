<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use App\Modules\Admin\Enums\FieldType;
use App\Modules\Admin\Services\ResourceWriter;
use BackedEnum;

/**
 * One editable field of a registry resource (Phase 19a).
 *
 * A field carries its own validation rules, which is the point: the generic writer validates
 * against the declaration rather than trusting whatever a form posted, so the registry is a
 * validated write path and not a mass-assignment hole with a Vue component on top. A resource that
 * declares no fields is read-only, and that is the default.
 *
 * @see ResourceWriter
 */
final readonly class ResourceField
{
    /**
     * @param  list<string>  $rules  Laravel validation rules, applied by the generic writer.
     * @param  array<string, string>  $options  For {@see FieldType::Select}: value => label.
     */
    public function __construct(
        public string $name,
        public string $label,
        public FieldType $type,
        public array $rules = [],
        public array $options = [],
        public ?string $help = null,
    ) {}

    /**
     * @param  list<string>  $rules
     */
    public static function text(string $name, string $label, array $rules = ['nullable', 'string', 'max:255'], ?string $help = null): self
    {
        return new self($name, $label, FieldType::Text, $rules, help: $help);
    }

    /**
     * @param  list<string>  $rules
     */
    public static function textarea(string $name, string $label, array $rules = ['nullable', 'string', 'max:10000'], ?string $help = null): self
    {
        return new self($name, $label, FieldType::Textarea, $rules, help: $help);
    }

    /**
     * @param  list<string>  $rules
     */
    public static function integer(string $name, string $label, array $rules = ['nullable', 'integer'], ?string $help = null): self
    {
        return new self($name, $label, FieldType::Integer, $rules, help: $help);
    }

    public static function boolean(string $name, string $label, ?string $help = null): self
    {
        return new self($name, $label, FieldType::Boolean, ['nullable', 'boolean'], help: $help);
    }

    /**
     * @param  array<string, string>  $options
     * @param  list<string>  $rules
     */
    public static function select(string $name, string $label, array $options, array $rules = ['nullable'], ?string $help = null): self
    {
        return new self($name, $label, FieldType::Select, [...$rules, 'in:'.implode(',', array_keys($options))], $options, $help);
    }

    /**
     * A select over a backed enum's cases, labelled by the enum's own `label()` where it has one.
     *
     * Reading the labels off the enum rather than repeating them here is what keeps the panel from
     * drifting: a case renamed in the module shows up renamed in the admin, and a case *added*
     * appears without anybody remembering this file exists.
     *
     * @param  class-string<BackedEnum>  $enum
     * @param  list<string>  $rules
     */
    public static function enum(string $name, string $label, string $enum, array $rules = ['nullable'], ?string $help = null): self
    {
        $options = [];

        foreach ($enum::cases() as $case) {
            $value = (string) $case->value;
            $options[$value] = method_exists($case, 'label') ? (string) $case->label() : $value;
        }

        return self::select($name, $label, $options, $rules, $help);
    }

    /**
     * @param  list<string>  $rules
     */
    public static function date(string $name, string $label, array $rules = ['nullable', 'date'], ?string $help = null): self
    {
        return new self($name, $label, FieldType::Date, $rules, help: $help);
    }

    public function isRequired(): bool
    {
        return in_array('required', $this->rules, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type->value,
            'required' => $this->isRequired(),
            'options' => $this->options,
            'help' => $this->help,
        ];
    }
}
