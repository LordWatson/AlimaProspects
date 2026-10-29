<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Casts;

use App\Modules\Enrichment\Data\SourcedValue;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast for `leads.field_sources`: a map of field name => {@see SourcedValue} on the model,
 * stored as a JSON object of the provenance records. Keeps per-field attribution typed in PHP while
 * it persists as plain JSON, so nothing writes a raw, unattributed provenance blob by hand.
 *
 * @implements CastsAttributes<array<string, SourcedValue>, array<string, SourcedValue>>
 */
final class SourcedValueMap implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, SourcedValue>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $field => $entry) {
            if (is_string($field) && is_array($entry)) {
                $map[$field] = SourcedValue::fromStorage($entry);
            }
        }

        return $map;
    }

    /**
     * @param  array<string, SourcedValue>|null  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        $storable = [];
        foreach ($value as $field => $sourced) {
            $storable[$field] = $sourced->toStorage();
        }

        // Substitute malformed UTF-8 (a URL/value can carry scraped bytes) so encoding never yields
        // an empty string that would corrupt the JSON column; drop to null if it somehow still fails.
        $json = json_encode($storable, JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }
}
