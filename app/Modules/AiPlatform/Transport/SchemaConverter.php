<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Transport;

use Illuminate\JsonSchema\Deserializer;
use Illuminate\JsonSchema\Types\Type;

/**
 * Bridges our stored JSON-schema arrays to Laravel's JSON schema types, which laravel/ai consumes
 * for structured output. The SDK's structured contract expects the root object's *properties* as a
 * `array<string, Type>`, so we deserialize each property and flag the required ones.
 */
final class SchemaConverter
{
    /**
     * @param  array<string, mixed>  $objectSchema  A JSON schema whose top level is an object.
     * @return array<string, Type>
     */
    public static function properties(array $objectSchema): array
    {
        /** @var array<string, array<string, mixed>> $properties */
        $properties = is_array($objectSchema['properties'] ?? null) ? $objectSchema['properties'] : [];

        /** @var array<string, int> $required */
        $required = array_flip(array_map('strval', is_array($objectSchema['required'] ?? null) ? $objectSchema['required'] : []));

        $out = [];

        foreach ($properties as $name => $definition) {
            $type = Deserializer::deserialize($definition);

            if (isset($required[(string) $name])) {
                $type->required();
            }

            $out[(string) $name] = $type;
        }

        return $out;
    }
}
