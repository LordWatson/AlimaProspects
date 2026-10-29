<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Services;

/**
 * A small, dependency-free validator for the JSON-Schema subset our prompt registry uses — the
 * same subset Laravel's own JSON schema builder emits (object/array/string/integer/number/boolean,
 * `type` unions incl. `null`, `properties`, `required`, `items`, `enum`, and length/range bounds).
 * It exists so a model response can be checked (and a retry triggered) independently of whichever
 * transport produced it. It validates; it does not coerce.
 */
final class JsonSchemaValidator
{
    /**
     * Validate a decoded value against a schema. Returns a list of human-readable error strings;
     * an empty list means the value is valid.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $types = $this->normaliseTypes($schema);

        if ($value === null) {
            return in_array('null', $types, true) || $types === []
                ? []
                : ["{$path}: expected ".implode('|', $types).', got null'];
        }

        // Pick the first non-null declared type to validate against (our schemas are single-type +
        // optional null; a true multi-type union is not used in our prompts).
        $expected = $this->primaryType($types, $value);

        $errors = match ($expected) {
            'object' => $this->validateObject($value, $schema, $path),
            'array' => $this->validateArray($value, $schema, $path),
            'string' => $this->validateString($value, $path),
            'integer' => $this->validateInteger($value, $path),
            'number' => $this->validateNumber($value, $path),
            'boolean' => is_bool($value) ? [] : ["{$path}: expected boolean"],
            default => [], // no type declared → accept
        };

        if ($errors === []) {
            $errors = array_merge($errors, $this->validateEnum($value, $schema, $path));
            $errors = array_merge($errors, $this->validateBounds($value, $schema, $path));
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function normaliseTypes(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if ($type === null) {
            return [];
        }

        return array_values(array_map('strval', is_array($type) ? $type : [$type]));
    }

    /**
     * @param  list<string>  $types
     */
    private function primaryType(array $types, mixed $value): ?string
    {
        $nonNull = array_values(array_filter($types, static fn (string $t): bool => $t !== 'null'));

        return $nonNull[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function validateObject(mixed $value, array $schema, string $path): array
    {
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            return ["{$path}: expected object"];
        }

        $errors = [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $key) {
            if (! array_key_exists((string) $key, $value)) {
                $errors[] = "{$path}: missing required property [{$key}]";
            }
        }

        foreach ($properties as $key => $propSchema) {
            if (is_array($propSchema) && array_key_exists((string) $key, $value)) {
                $errors = array_merge($errors, $this->validate($value[$key], $propSchema, "{$path}.{$key}"));
            }
        }

        if (($schema['additionalProperties'] ?? null) === false) {
            foreach (array_keys($value) as $key) {
                if (! array_key_exists((string) $key, $properties)) {
                    $errors[] = "{$path}: unexpected property [{$key}]";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function validateArray(mixed $value, array $schema, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return ["{$path}: expected array"];
        }

        $errors = [];
        $count = count($value);

        if (isset($schema['minItems']) && $count < (int) $schema['minItems']) {
            $errors[] = "{$path}: expected at least {$schema['minItems']} item(s), got {$count}";
        }

        if (isset($schema['maxItems']) && $count > (int) $schema['maxItems']) {
            $errors[] = "{$path}: expected at most {$schema['maxItems']} item(s), got {$count}";
        }

        if (is_array($schema['items'] ?? null)) {
            foreach ($value as $i => $item) {
                $errors = array_merge($errors, $this->validate($item, $schema['items'], "{$path}[{$i}]"));
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateString(mixed $value, string $path): array
    {
        return is_string($value) ? [] : ["{$path}: expected string"];
    }

    /**
     * @return list<string>
     */
    private function validateInteger(mixed $value, string $path): array
    {
        return is_int($value) ? [] : ["{$path}: expected integer"];
    }

    /**
     * @return list<string>
     */
    private function validateNumber(mixed $value, string $path): array
    {
        return (is_int($value) || is_float($value)) ? [] : ["{$path}: expected number"];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function validateEnum(mixed $value, array $schema, string $path): array
    {
        if (! isset($schema['enum']) || ! is_array($schema['enum'])) {
            return [];
        }

        return in_array($value, $schema['enum'], true)
            ? []
            : ["{$path}: value is not one of the allowed options"];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function validateBounds(mixed $value, array $schema, string $path): array
    {
        $errors = [];

        if (is_string($value)) {
            $length = mb_strlen($value);

            if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
                $errors[] = "{$path}: shorter than the minimum length of {$schema['minLength']}";
            }

            if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
                $errors[] = "{$path}: longer than the maximum length of {$schema['maxLength']}";
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path}: below the minimum of {$schema['minimum']}";
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path}: above the maximum of {$schema['maximum']}";
            }
        }

        return $errors;
    }
}
