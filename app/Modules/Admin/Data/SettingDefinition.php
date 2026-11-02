<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use App\Modules\Admin\Enums\SettingType;
use InvalidArgumentException;

/**
 * One declared platform setting, from `config/admin.php` (Phase 19b).
 *
 * The declaration is what makes the settings store a schema rather than a key-value bin: a key that
 * is not here cannot be read or written, and `PlatformSettingsSchemaTest` asserts that every
 * declaration is well-formed. `operational` separates the switches an operator is expected to touch
 * at 2am from the tuning values that happen to live in the same table, so the screen can lead with
 * the former.
 */
final readonly class SettingDefinition
{
    public function __construct(
        public string $key,
        public SettingType $type,
        public mixed $default,
        public string $label,
        public string $help,
        /** Whether this is a switch an operator reaches for during an incident. */
        public bool $operational,
        /**
         * The closed list of values this setting accepts, or null for free text.
         *
         * @var list<string>|null
         */
        public ?array $allowed = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        $type = SettingType::tryFrom((string) ($config['type'] ?? ''));

        // Not a silent fall back to Bool. A mistyped `type` would turn an integer setting into a
        // checkbox on the screen and cast its value to `true`, which is a configuration bug that
        // looks like a product decision.
        if (! $type instanceof SettingType) {
            throw new InvalidArgumentException(
                'Platform setting "'.$key.'" declares an unknown type: '
                    .json_encode($config['type'] ?? null).'.',
            );
        }

        return new self(
            key: $key,
            type: $type,
            // The values this setting may hold, when it is constrained to a closed list. Without
            // it a `string_list` takes anything, and `signal` instead of `signals` saves cleanly,
            // does nothing and leaves the screen looking right — during an incident.
            allowed: self::allowedFrom($config),
            // Cast through the declared type, so a default written as `1` in config and one written
            // as `true` are the same value — and so `get()` can compare an override against it.
            default: $type->cast($config['default'] ?? null),
            label: (string) ($config['label'] ?? $key),
            help: (string) ($config['help'] ?? ''),
            operational: (bool) ($config['operational'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>|null
     */
    private static function allowedFrom(array $config): ?array
    {
        if (! isset($config['allowed']) || ! is_array($config['allowed'])) {
            return null;
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $config['allowed']),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'allowed' => $this->allowed,
            'type' => $this->type->value,
            'default' => $this->default,
            'label' => $this->label,
            'help' => $this->help,
            'operational' => $this->operational,
        ];
    }
}
