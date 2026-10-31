<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

use App\Modules\Admin\Data\SettingDefinition;

/**
 * The declared type of a platform setting (Phase 19b).
 *
 * Small on purpose — three types, no objects, no nesting. The settings store is for **operational
 * switches**, and a setting whose shape needs more than this is configuration that belongs in a
 * config file and a deploy, not behind a form an operator can reach at 2am.
 *
 * The type is what makes the store type-safe across a JSON column: a value comes back from the
 * database as whatever `json_decode` made of it, and {@see self::cast()} is what turns it back into
 * the thing the declaration promised. Without that, `"0"` from a form field would be a truthy
 * string and a kill switch would read as on.
 *
 * @see SettingDefinition
 */
enum SettingType: string
{
    case Bool = 'bool';
    case Int = 'int';
    /** A list of strings — a set of kill-switched capabilities, say. */
    case StringList = 'string_list';

    public function cast(mixed $value): mixed
    {
        return match ($this) {
            // `filter_var` rather than a bare cast, because a form posts `"0"` and `"false"`, and
            // both of those are truthy strings. A kill switch that read as on would be the worst
            // possible failure of this method.
            self::Bool => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            self::Int => is_numeric($value) ? (int) $value : 0,
            self::StringList => $this->castList($value),
        };
    }

    /**
     * @return list<string>
     */
    private function castList(mixed $value): array
    {
        if (is_string($value)) {
            // A textarea or a comma-separated field, which is how an operator types a list.
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== '',
        )));
    }

    /**
     * The validation rules a form field for this type gets.
     *
     * **`sometimes` on every one of them**, because a settings PATCH is allowed to carry a subset:
     * `UpdateSettingsRequest::changes()` only applies the keys that were submitted, so a request
     * that touches one switch should not be refused for omitting an unrelated integer. Without it,
     * every save would have to post every setting — which the screen happens to do, and which a
     * script or a future partial form would not.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return match ($this) {
            self::Bool => ['sometimes', 'nullable', 'boolean'],
            self::Int => ['sometimes', 'required', 'integer', 'min:0'],
            // Not `array`: {@see self::castList()} deliberately accepts a textarea or a
            // comma-separated string, because that is how an operator types a list. A closed list's
            // *items* are checked against the cast value instead — see `UpdateSettingsRequest`.
            self::StringList => ['sometimes', 'nullable'],
        };
    }
}
